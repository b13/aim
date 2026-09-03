<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Domain\Repository;

use B13\Aim\Domain\Repository\RequestLogDemand;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Grading\GradeLabel;
use B13\Aim\Grading\GradeStatus;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RequestLogRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function findByDemandOrderedByUsernameSortsByResolvedUsernameNotRawUserId(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);

        // uid 1001 is numerically smaller but alphabetically LATER ("zebra") than
        // uid 1002 ("apple") — this only passes if sorting genuinely joins on the
        // resolved username instead of ordering by the raw user_id column.
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1001,
            'pid' => 0,
            'username' => 'zebra',
        ]);
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1002,
            'pid' => 0,
            'username' => 'apple',
        ]);

        $logRepo->log(['request_type' => 'TextGenerationRequest', 'provider_identifier' => 'test', 'user_id' => 1001]);
        $logRepo->log(['request_type' => 'TextGenerationRequest', 'provider_identifier' => 'test', 'user_id' => 1002]);

        $rows = $logRepo->findByDemand(new RequestLogDemand(orderField: 'username', orderDirection: 'asc'));

        self::assertCount(2, $rows);
        self::assertSame(1002, (int)$rows[0]['user_id'], '"apple" should sort first');
        self::assertSame(1001, (int)$rows[1]['user_id'], '"zebra" should sort second');
    }

    #[Test]
    public function countByDemandIsUnaffectedByTheUsernameJoin(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);

        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1003,
            'pid' => 0,
            'username' => 'someone',
        ]);
        $logRepo->log(['request_type' => 'TextGenerationRequest', 'provider_identifier' => 'test', 'user_id' => 1003]);
        // A row with no matching be_users record at all — the join must be LEFT,
        // not INNER, or this row would silently vanish from both count and results.
        $logRepo->log(['request_type' => 'TextGenerationRequest', 'provider_identifier' => 'test', 'user_id' => 0]);

        $demand = new RequestLogDemand(orderField: 'username', orderDirection: 'asc');
        self::assertSame(2, $logRepo->countByDemand($demand));
        self::assertCount(2, $logRepo->findByDemand($demand));
    }

    /**
     * getQueryBuilder() always starts from an explicit select('*'). Every
     * aggregate/GROUP BY query built on top of it must REPLACE that select
     * list (selectLiteral()), not append to it (addSelectLiteral()), or the
     * `*` leaks every column of the table into the result alongside the
     * aggregates, which MySQL's ONLY_FULL_GROUP_BY rejects outright
     * (see https://github.com/b13/aim/issues/27). SQLite tolerates the
     * broken query and just returns the extra columns, which is exactly
     * what these tests catch.
     */
    #[Test]
    public function getStatisticsByProviderOnlySelectsTheIntendedColumns(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logRepo->log(['request_type' => 'TextGenerationRequest', 'provider_identifier' => 'test', 'cost' => 1.0]);

        $rows = $logRepo->getStatisticsByProvider();

        self::assertCount(1, $rows);
        self::assertSame(
            ['provider_identifier', 'request_count', 'total_cost', 'total_tokens', 'avg_duration_ms', 'successful_requests'],
            array_keys($rows[0]),
        );
    }

    #[Test]
    public function getStatisticsByExtensionOnlySelectsTheIntendedColumns(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logRepo->log(['request_type' => 'TextGenerationRequest', 'provider_identifier' => 'test', 'extension_key' => 'some_ext', 'cost' => 1.0]);

        $rows = $logRepo->getStatisticsByExtension();

        self::assertCount(1, $rows);
        self::assertSame(
            ['extension_key', 'request_count', 'total_cost', 'total_tokens', 'avg_duration_ms'],
            array_keys($rows[0]),
        );
    }

    /**
     * getStatistics(), getModelPerformanceProfile() and
     * getLastUsedPerConfiguration() all re-key their rows into a fixed
     * shape before returning, which would silently hide the same `SELECT
     * *, ...` regression the two tests above catch directly. Asserted here
     * instead on the built query's own SQL, via the private QueryBuilder
     * factories those methods were split from for exactly this reason.
     */
    #[Test]
    public function statisticsQueryHasNoStraySelectStar(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $qb = (new \ReflectionMethod($logRepo, 'buildStatisticsQueryBuilder'))->invoke($logRepo);

        self::assertStringNotContainsString('SELECT *,', $qb->getSQL());
    }

    #[Test]
    public function modelPerformanceQueryHasNoStraySelectStar(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $qb = (new \ReflectionMethod($logRepo, 'buildModelPerformanceQueryBuilder'))->invoke($logRepo, '');

        self::assertStringNotContainsString('SELECT *,', $qb->getSQL());
    }

    #[Test]
    public function lastUsedPerConfigurationQueryHasNoStraySelectStar(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $qb = (new \ReflectionMethod($logRepo, 'buildLastUsedPerConfigurationQueryBuilder'))->invoke($logRepo);

        self::assertStringNotContainsString('SELECT *,', $qb->getSQL());
    }

    #[Test]
    public function modelPerformanceProfileAggregatesGradesOverDoneRowsOnly(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);

        // Three graded "done" rows for cheap-model: scores 0.6, 0.8, 1.0 → avg 0.8
        foreach ([0.6, 0.8, 1.0] as $score) {
            $logRepo->log($this->row('cheap-model', 0.5, GradeStatus::Done, $score));
        }
        // One failed and one ungraded row — must be excluded from the grade average
        $logRepo->log($this->row('cheap-model', 0.4, GradeStatus::Failed, 0.0));
        $logRepo->log($this->row('cheap-model', 0.4, GradeStatus::None, 0.0));

        $profiles = $logRepo->getModelPerformanceProfile('TextGenerationRequest');
        $cheap = $this->profileFor($profiles, 'cheap-model');

        self::assertSame(5, $cheap['request_count']);
        self::assertSame(3, $cheap['graded_count']);
        self::assertEqualsWithDelta(0.8, $cheap['avg_grade_score'], 0.0001);
    }

    #[Test]
    public function modelPerformanceProfileReportsZeroGradesForUngradedModel(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logRepo->log($this->row('ungraded-model', 0.5, GradeStatus::None, 0.0));
        $logRepo->log($this->row('ungraded-model', 0.5, GradeStatus::None, 0.0));

        $profiles = $logRepo->getModelPerformanceProfile('TextGenerationRequest');
        $model = $this->profileFor($profiles, 'ungraded-model');

        self::assertSame(2, $model['request_count']);
        self::assertSame(0, $model['graded_count']);
        self::assertSame(0.0, $model['avg_grade_score']);
    }

    private function row(string $model, float $cost, GradeStatus $status, float $gradeScore): array
    {
        return [
            'crdate' => time(),
            'request_type' => 'TextGenerationRequest',
            'provider_identifier' => 'test',
            'model_used' => $model,
            'success' => 1,
            'cost' => $cost,
            'total_tokens' => 100,
            'grade_status' => $status->value,
            'grade_score' => $gradeScore,
            'grade_label' => $status === GradeStatus::Done ? GradeLabel::fromScore($gradeScore)->value : '',
        ];
    }

    /**
     * @param list<array<string, mixed>> $profiles
     * @return array<string, mixed>
     */
    private function profileFor(array $profiles, string $model): array
    {
        foreach ($profiles as $profile) {
            if ($profile['model_used'] === $model) {
                return $profile;
            }
        }
        self::fail('No performance profile for model "' . $model . '".');
    }
}
