<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Governance;

use B13\Aim\Domain\Repository\UsageBudgetRepository;
use B13\Aim\Governance\BudgetService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for budget tracking with real database.
 */
final class BudgetTrackingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function recordUsageCreatesAndIncrementsRecord(): void
    {
        $repo = $this->get(UsageBudgetRepository::class);

        // First record
        $repo->recordUsage(42, 'monthly', 100, 0.05);
        $usage = $repo->getCurrentUsage(42, 'monthly');

        self::assertSame(100, $usage['tokens_used']);
        self::assertEqualsWithDelta(0.05, $usage['cost_used'], 0.0001);
        self::assertSame(1, $usage['requests_used']);

        // Second record — increments
        $repo->recordUsage(42, 'monthly', 200, 0.10);
        $usage = $repo->getCurrentUsage(42, 'monthly');

        self::assertSame(300, $usage['tokens_used']);
        self::assertEqualsWithDelta(0.15, $usage['cost_used'], 0.0001);
        self::assertSame(2, $usage['requests_used']);
    }

    #[Test]
    public function differentUsersTrackSeparately(): void
    {
        $repo = $this->get(UsageBudgetRepository::class);

        $repo->recordUsage(1, 'monthly', 500, 1.0);
        $repo->recordUsage(2, 'monthly', 100, 0.1);

        $usage1 = $repo->getCurrentUsage(1, 'monthly');
        $usage2 = $repo->getCurrentUsage(2, 'monthly');

        self::assertSame(500, $usage1['tokens_used']);
        self::assertSame(100, $usage2['tokens_used']);
    }

    #[Test]
    public function differentPeriodsTrackSeparately(): void
    {
        $repo = $this->get(UsageBudgetRepository::class);

        $repo->recordUsage(1, 'daily', 100, 0.05);
        $repo->recordUsage(1, 'monthly', 500, 0.50);

        $daily = $repo->getCurrentUsage(1, 'daily');
        $monthly = $repo->getCurrentUsage(1, 'monthly');

        self::assertSame(100, $daily['tokens_used']);
        self::assertSame(500, $monthly['tokens_used']);
    }

    #[Test]
    public function budgetCheckDeniesWhenExceeded(): void
    {
        $repo = $this->get(UsageBudgetRepository::class);
        $service = new BudgetService($repo);

        // Record some usage
        $repo->recordUsage(5, 'monthly', 0, 0);
        // Manually set high cost
        $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_usage_budget')
            ->update('tx_aim_usage_budget', ['cost_used' => 55.0], ['user_id' => 5]);

        $result = $service->checkBudget(5, [
            'period' => 'monthly',
            'maxCost' => '50.00',
        ]);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Cost budget exceeded', $result->reason);
    }

    #[Test]
    public function budgetCheckAllowsWhenWithinLimits(): void
    {
        $repo = $this->get(UsageBudgetRepository::class);
        $service = new BudgetService($repo);

        $repo->recordUsage(5, 'monthly', 1000, 10.0);

        $result = $service->checkBudget(5, [
            'period' => 'monthly',
            'maxCost' => '50.00',
            'maxTokens' => '100000',
            'maxRequests' => '500',
        ]);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function expiredPeriodResetsCounters(): void
    {
        $repo = $this->get(UsageBudgetRepository::class);

        // Create record with old period_start
        $repo->recordUsage(99, 'daily', 10000, 100.0);

        // Manually set period_start to yesterday
        $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_usage_budget')
            ->update(
                'tx_aim_usage_budget',
                ['period_start' => time() - 90000], // > 24h ago
                ['user_id' => 99, 'period_type' => 'daily'],
            );

        // getCurrentUsage should detect expiration and reset
        $usage = $repo->getCurrentUsage(99, 'daily');

        self::assertSame(0, $usage['tokens_used']);
        self::assertEqualsWithDelta(0.0, $usage['cost_used'], 0.0001);
        self::assertSame(0, $usage['requests_used']);
    }
}