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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class RequestLogRepository
{
    private const TABLE = 'tx_aim_request_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function log(array $data): void
    {
        $data['crdate'] = (int)($data['crdate'] ?? $GLOBALS['EXEC_TIME'] ?? time());
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->insert(self::TABLE, $data);
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
        return (int)$this->getQueryBuilderForDemand($demand)
            ->count('*')
            ->executeQuery()
            ->fetchOne();
    }

    public function getStatistics(): array
    {
        $qb = $this->getQueryBuilder();
        $result = $qb
            ->addSelectLiteral(
                $qb->expr()->count('*', 'total_requests'),
                'SUM(cost) AS total_cost',
                'SUM(prompt_tokens) AS total_prompt_tokens',
                'SUM(completion_tokens) AS total_completion_tokens',
                'SUM(cached_tokens) AS total_cached_tokens',
                'SUM(reasoning_tokens) AS total_reasoning_tokens',
                'SUM(total_tokens) AS total_tokens',
                'AVG(duration_ms) AS avg_duration_ms',
                'SUM(success) AS successful_requests',
            )
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

    public function getStatisticsByProvider(): array
    {
        $qb = $this->getQueryBuilder();
        return $qb
            ->addSelectLiteral(
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
            ->addSelectLiteral(
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
     * @return list<array{model_used: string, request_count: int, avg_cost: float, avg_duration_ms: int, success_rate: float, avg_tokens: int}>
     */
    public function getModelPerformanceProfile(string $requestType = ''): array
    {
        $qb = $this->getQueryBuilder();
        $qb->addSelectLiteral(
                'model_used',
                $qb->expr()->count('*', 'request_count'),
                'AVG(cost) AS avg_cost',
                'AVG(duration_ms) AS avg_duration_ms',
                'SUM(success) AS successful_requests',
                'AVG(total_tokens) AS avg_tokens',
            );
        if ($requestType !== '') {
            $qb->where($qb->expr()->eq('request_type', $qb->createNamedParameter($requestType)));
            $qb->andWhere($qb->expr()->neq('model_used', $qb->createNamedParameter('')));
        } else {
            $qb->where($qb->expr()->neq('model_used', $qb->createNamedParameter('')));
        }
        $rows = $qb
            ->groupBy('model_used')
            ->orderBy('request_count', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $count = (int)$row['request_count'];
            $successful = (int)$row['successful_requests'];
            return [
                'model_used' => $row['model_used'],
                'request_count' => $count,
                'avg_cost' => round((float)$row['avg_cost'], 6),
                'avg_duration_ms' => (int)$row['avg_duration_ms'],
                'success_rate' => $count > 0 ? round($successful / $count * 100, 1) : 0,
                'avg_tokens' => (int)$row['avg_tokens'],
            ];
        }, $rows);
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

    protected function getQueryBuilderForDemand(RequestLogDemand $demand): QueryBuilder
    {
        $qb = $this->getQueryBuilder();
        $qb->orderBy($demand->getOrderField(), $demand->getOrderDirection());
        if ($demand->getOrderField() !== 'uid') {
            $qb->addOrderBy('uid', 'desc');
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
        $qb = $this->getQueryBuilder();
        $rows = $qb
            ->addSelectLiteral(
                'configuration_uid',
                'MAX(crdate) AS last_used',
            )
            ->where($qb->expr()->gt('configuration_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->groupBy('configuration_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['configuration_uid']] = (int)$row['last_used'];
        }
        return $result;
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
