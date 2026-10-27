<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Domain\Repository;

use B13\Aim\Grading\GradeLabel;
use B13\Aim\Grading\GradeStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class RequestLogRepository
{
    private const TABLE = 'tx_aim_request_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function log(array $data): int
    {
        $data['crdate'] = (int)($data['crdate'] ?? $GLOBALS['EXEC_TIME'] ?? time());
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $data);
        return (int)$connection->lastInsertId();
    }

    public function findByUid(int $uid): ?array
    {
        $qb = $this->getQueryBuilder();
        $row = $qb
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function markGradePending(int $uid): void
    {
        if ($uid <= 0) {
            return;
        }
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['grade_status' => GradeStatus::Pending->value],
            ['uid' => $uid],
        );
    }

    public function updateGrade(
        int $uid,
        float $score,
        GradeLabel $label,
        string $reason,
        string $judgeModel,
        float $judgeCost,
        int $durationMs,
    ): void {
        if ($uid <= 0) {
            return;
        }
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'grade_status' => GradeStatus::Done->value,
                'grade_score' => $score,
                'grade_label' => $label->value,
                'grade_reason' => $reason,
                'judge_model' => $judgeModel,
                'judge_cost' => $judgeCost,
                'grade_duration_ms' => $durationMs,
                'grade_error' => '',
            ],
            ['uid' => $uid],
        );
    }

    public function markGradeFailed(int $uid, string $error): void
    {
        if ($uid <= 0) {
            return;
        }
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'grade_status' => GradeStatus::Failed->value,
                'grade_error' => mb_substr($error, 0, 500),
            ],
            ['uid' => $uid],
        );
    }

    /**
     * Find rows that are marked pending and have been waiting at least $minAgeSeconds.
     * Used by the scheduler safety-net command to pick up rows the shutdown handler missed.
     *
     * @return list<array<string, mixed>>
     */
    public function findPendingGrades(int $minAgeSeconds, int $limit): array
    {
        $cutoff = ($GLOBALS['EXEC_TIME'] ?? time()) - $minAgeSeconds;
        $qb = $this->getQueryBuilder();
        return $qb
            ->where(
                $qb->expr()->eq('grade_status', $qb->createNamedParameter(GradeStatus::Pending->value)),
                $qb->expr()->lte('crdate', $qb->createNamedParameter($cutoff, Connection::PARAM_INT)),
            )
            ->orderBy('crdate', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function countPendingGradesOlderThan(int $minAgeSeconds): int
    {
        $cutoff = ($GLOBALS['EXEC_TIME'] ?? time()) - $minAgeSeconds;
        $qb = $this->getQueryBuilder();
        return (int)$qb
            ->count('*')
            ->where(
                $qb->expr()->eq('grade_status', $qb->createNamedParameter(GradeStatus::Pending->value)),
                $qb->expr()->lte('crdate', $qb->createNamedParameter($cutoff, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByDemand(RequestLogDemand $demand): array
    {
        return $this->getQueryBuilderForDemand($demand)
            ->setMaxResults($demand->getLimit())
            ->setFirstResult($demand->getOffset())
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function countByDemand(RequestLogDemand $demand): int
    {
        return (int)$this->getQueryBuilderForDemand($demand, false)
            ->count('*')
            ->executeQuery()
            ->fetchOne();
    }

    public function getStatistics(): array
    {
        $result = $this->buildStatisticsQueryBuilder()
            ->executeQuery()
            ->fetchAssociative();

        $totalRequests = (int)($result['total_requests'] ?? 0);
        $successfulRequests = (int)($result['successful_requests'] ?? 0);

        return [
            'total_requests' => $totalRequests,
            'total_cost' => (float)($result['total_cost'] ?? 0),
            'total_prompt_tokens' => (int)($result['total_prompt_tokens'] ?? 0),
            'total_completion_tokens' => (int)($result['total_completion_tokens'] ?? 0),
            'total_cached_tokens' => (int)($result['total_cached_tokens'] ?? 0),
            'total_reasoning_tokens' => (int)($result['total_reasoning_tokens'] ?? 0),
            'total_tokens' => (int)($result['total_tokens'] ?? 0),
            'avg_duration_ms' => (int)($result['avg_duration_ms'] ?? 0),
            'success_rate' => $totalRequests > 0 ? round($successfulRequests / $totalRequests * 100, 1) : 0,
        ];
    }

    private function buildStatisticsQueryBuilder(): QueryBuilder
    {
        $qb = $this->getQueryBuilder();
        return $qb->selectLiteral(
            $qb->expr()->count('*', 'total_requests'),
            'SUM(cost) AS total_cost',
            'SUM(prompt_tokens) AS total_prompt_tokens',
            'SUM(completion_tokens) AS total_completion_tokens',
            'SUM(cached_tokens) AS total_cached_tokens',
            'SUM(reasoning_tokens) AS total_reasoning_tokens',
            'SUM(total_tokens) AS total_tokens',
            'AVG(duration_ms) AS avg_duration_ms',
            'SUM(success) AS successful_requests',
        );
    }

    public function getStatisticsByProvider(): array
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->selectLiteral(
                'provider_identifier',
                $qb->expr()->count('*', 'request_count'),
                'SUM(cost) AS total_cost',
                'SUM(total_tokens) AS total_tokens',
                'AVG(duration_ms) AS avg_duration_ms',
                'SUM(success) AS successful_requests',
            )
            ->groupBy('provider_identifier')
            ->orderBy('request_count', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function getStatisticsByExtension(): array
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->selectLiteral(
                'extension_key',
                $qb->expr()->count('*', 'request_count'),
                'SUM(cost) AS total_cost',
                'SUM(total_tokens) AS total_tokens',
                'AVG(duration_ms) AS avg_duration_ms',
            )
            ->where($qb->expr()->neq('extension_key', $qb->createNamedParameter('')))
            ->groupBy('extension_key')
            ->orderBy('request_count', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Performance profile per model for a given request type.
     * Used by smart routing middleware.
     *
     * "model_used" is the model as the configuration names it (model_configured).
     *
     * @return list<array{model_used: string, request_count: int, avg_cost: float, avg_duration_ms: int, success_rate: float, avg_tokens: int, graded_count: int, avg_grade_score: float}>
     */
    public function getModelPerformanceProfile(string $requestType = ''): array
    {
        $rows = $this->buildModelPerformanceQueryBuilder($requestType)
            ->executeQuery()
            ->fetchAllAssociative();

        $merged = [];
        foreach ($rows as $row) {
            // model_used is what the provider reported, often a dated snapshot
            // (gpt-4o-mini-2024-07-18) or an alias target no configuration names.
            // Entries logged before model_configured existed keep that name.
            $model = (string)$row['model_configured'] ?: (string)$row['model_used'];
            if (!isset($merged[$model])) {
                $merged[$model] = ['model_used' => $model, 'request_count' => 0, 'cost_sum' => 0.0, 'duration_sum' => 0, 'tokens_sum' => 0, 'successful_requests' => 0, 'grade_score_sum' => 0.0, 'graded_count' => 0];
            }
            $merged[$model]['request_count'] += (int)$row['request_count'];
            $merged[$model]['cost_sum'] += (float)$row['cost_sum'];
            $merged[$model]['duration_sum'] += (int)$row['duration_sum'];
            $merged[$model]['tokens_sum'] += (int)$row['tokens_sum'];
            $merged[$model]['successful_requests'] += (int)$row['successful_requests'];
            $merged[$model]['grade_score_sum'] += (float)$row['grade_score_sum'];
            $merged[$model]['graded_count'] += (int)$row['graded_count'];
        }
        usort($merged, static fn(array $a, array $b): int => $b['request_count'] <=> $a['request_count']);

        return array_map(static function (array $row): array {
            $count = $row['request_count'];
            $gradedCount = $row['graded_count'];
            return [
                'model_used' => $row['model_used'],
                'request_count' => $count,
                'avg_cost' => round($row['cost_sum'] / $count, 6),
                'avg_duration_ms' => intdiv($row['duration_sum'], $count),
                'success_rate' => round($row['successful_requests'] / $count * 100, 1),
                'avg_tokens' => intdiv($row['tokens_sum'], $count),
                'graded_count' => $gradedCount,
                'avg_grade_score' => $gradedCount > 0 ? round($row['grade_score_sum'] / $gradedCount, 4) : 0.0,
            ];
        }, $merged);
    }

    private function buildModelPerformanceQueryBuilder(string $requestType): QueryBuilder
    {
        $done = GradeStatus::Done->value;
        $qb = $this->getQueryBuilder();
        $qb->selectLiteral(
            'model_used',
            'model_configured',
            $qb->expr()->count('*', 'request_count'),
            'SUM(cost) AS cost_sum',
            'SUM(duration_ms) AS duration_sum',
            'SUM(success) AS successful_requests',
            'SUM(total_tokens) AS tokens_sum',
            sprintf("SUM(CASE WHEN grade_status = '%s' THEN grade_score ELSE 0 END) AS grade_score_sum", $done),
            sprintf("SUM(CASE WHEN grade_status = '%s' THEN 1 ELSE 0 END) AS graded_count", $done),
        );
        if ($requestType !== '') {
            $qb->where($qb->expr()->eq('request_type', $qb->createNamedParameter($requestType)));
            $qb->andWhere($qb->expr()->neq('model_used', $qb->createNamedParameter('')));
        } else {
            $qb->where($qb->expr()->neq('model_used', $qb->createNamedParameter('')));
        }
        return $qb->groupBy('model_used', 'model_configured');
    }

    public function getDistinctProviders(): array
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->select('provider_identifier')
            ->groupBy('provider_identifier')
            ->orderBy('provider_identifier')
            ->executeQuery()
            ->fetchFirstColumn();
    }

    public function getDistinctExtensionKeys(): array
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->select('extension_key')
            ->where($qb->expr()->neq('extension_key', $qb->createNamedParameter('')))
            ->groupBy('extension_key')
            ->orderBy('extension_key')
            ->executeQuery()
            ->fetchFirstColumn();
    }

    public function getDistinctRequestTypes(): array
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->select('request_type')
            ->groupBy('request_type')
            ->orderBy('request_type')
            ->executeQuery()
            ->fetchFirstColumn();
    }

    public function getDistinctModels(): array
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->select('model_used')
            ->where($qb->expr()->neq('model_used', $qb->createNamedParameter('')))
            ->groupBy('model_used')
            ->orderBy('model_used')
            ->executeQuery()
            ->fetchFirstColumn();
    }

    protected function getQueryBuilderForDemand(RequestLogDemand $demand, bool $withOrdering = true): QueryBuilder
    {
        $qb = $this->getQueryBuilder();

        if ($demand->getOrderField() === 'username') {
            // "username" isn't a real column here. Sorting by the raw user_id
            // would order by internal ID, not the resolved username the column
            // actually displays, which is confusing. Join be_users instead.
            // Re-select only this table's columns: with the join in place, a
            // bare "*" would pull in be_users' columns too (including its own
            // "uid", colliding with this table's "uid" key in the result row).
            $qb->selectLiteral(self::TABLE . '.*')
                ->leftJoin(
                    self::TABLE,
                    'be_users',
                    'be_users',
                    $qb->expr()->eq(self::TABLE . '.user_id', $qb->quoteIdentifier('be_users.uid'))
                );
            if ($withOrdering) {
                $qb->orderBy('be_users.username', $demand->getOrderDirection())
                    ->addOrderBy(self::TABLE . '.uid', 'desc');
            }
        } elseif ($withOrdering) {
            $qb->orderBy($demand->getOrderField(), $demand->getOrderDirection());
            if ($demand->getOrderField() !== 'uid') {
                $qb->addOrderBy('uid', 'desc');
            }
        }

        $constraints = [];
        if ($demand->hasProviderIdentifier()) {
            $constraints[] = $qb->expr()->eq(
                'provider_identifier',
                $qb->createNamedParameter($demand->getProviderIdentifier())
            );
        }
        if ($demand->hasExtensionKey()) {
            $constraints[] = $qb->expr()->eq(
                'extension_key',
                $qb->createNamedParameter($demand->getExtensionKey())
            );
        }
        if ($demand->hasRequestType()) {
            $constraints[] = $qb->expr()->eq(
                'request_type',
                $qb->createNamedParameter($demand->getRequestType())
            );
        }
        if ($demand->hasModelUsed()) {
            $constraints[] = $qb->expr()->eq(
                'model_used',
                $qb->createNamedParameter($demand->getModelUsed())
            );
        }
        if ($demand->hasSuccess()) {
            $constraints[] = $qb->expr()->eq(
                'success',
                $qb->createNamedParameter($demand->getSuccess() ? 1 : 0, Connection::PARAM_INT)
            );
        }
        if ($demand->hasDateFrom()) {
            $constraints[] = $qb->expr()->gte(
                'crdate',
                $qb->createNamedParameter($demand->getDateFrom(), Connection::PARAM_INT)
            );
        }
        if ($demand->hasDateTo()) {
            $constraints[] = $qb->expr()->lte(
                'crdate',
                $qb->createNamedParameter($demand->getDateTo(), Connection::PARAM_INT)
            );
        }
        if ($demand->hasGradeStatus()) {
            $constraints[] = $qb->expr()->eq(
                'grade_status',
                $qb->createNamedParameter($demand->getGradeStatus())
            );
        }
        if ($demand->hasGradeLabel()) {
            $constraints[] = $qb->expr()->eq(
                'grade_label',
                $qb->createNamedParameter($demand->getGradeLabel())
            );
        }

        if (!empty($constraints)) {
            $qb->where(...$constraints);
        }
        return $qb;
    }

    /**
     * Get the last request timestamp per configuration UID.
     *
     * @return array<int, int> configurationUid => timestamp
     */
    public function getLastUsedPerConfiguration(): array
    {
        $rows = $this->buildLastUsedPerConfigurationQueryBuilder()
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['configuration_uid']] = (int)$row['last_used'];
        }
        return $result;
    }

    private function buildLastUsedPerConfigurationQueryBuilder(): QueryBuilder
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->selectLiteral(
                'configuration_uid',
                'MAX(crdate) AS last_used',
            )
            ->where($qb->expr()->gt('configuration_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->groupBy('configuration_uid');
    }

    /**
     * Resolve user IDs to usernames from be_users.
     *
     * @param list<int> $userIds
     * @return array<int, string> uid => username
     */
    public function resolveUsernames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $qb->getRestrictions()->removeAll();
        $rows = $qb
            ->select('uid', 'username')
            ->from('be_users')
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($userIds, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = (string)$row['username'];
        }
        return $map;
    }

    public function countRecentRequestsByUser(int $userId, int $sinceTimestamp): int
    {
        $qb = $this->getQueryBuilder();
        return (int)$qb
            ->count('*')
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, Connection::PARAM_INT)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($sinceTimestamp, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    protected function getQueryBuilder(): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')->from(self::TABLE);
        return $qb;
    }
}
