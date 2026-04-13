<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Governance;

use B13\Aim\Domain\Repository\UsageBudgetRepository;
use B13\Aim\Governance\BudgetService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BudgetServiceTest extends TestCase
{
    #[Test]
    public function allowsWhenNoLimitsConfigured(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->expects($this->never())->method('getCurrentUsage');

        $service = new BudgetService($repository);
        $result = $service->checkBudget(1, []);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function allowsWhenAllLimitsAreZero(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->expects($this->never())->method('getCurrentUsage');

        $service = new BudgetService($repository);
        $result = $service->checkBudget(1, [
            'maxCost' => '0',
            'maxTokens' => '0',
            'maxRequests' => '0',
        ]);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function allowsWhenWithinCostBudget(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->method('getCurrentUsage')->willReturn([
            'tokens_used' => 100,
            'cost_used' => 5.0,
            'requests_used' => 10,
        ]);

        $service = new BudgetService($repository);
        $result = $service->checkBudget(1, [
            'period' => 'monthly',
            'maxCost' => '50.00',
        ]);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function deniesWhenCostBudgetExceeded(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->method('getCurrentUsage')->willReturn([
            'tokens_used' => 100000,
            'cost_used' => 50.01,
            'requests_used' => 500,
        ]);

        $service = new BudgetService($repository);
        $result = $service->checkBudget(1, [
            'period' => 'monthly',
            'maxCost' => '50.00',
        ]);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Cost budget exceeded', $result->reason);
    }

    #[Test]
    public function deniesWhenTokenBudgetExceeded(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->method('getCurrentUsage')->willReturn([
            'tokens_used' => 500001,
            'cost_used' => 1.0,
            'requests_used' => 10,
        ]);

        $service = new BudgetService($repository);
        $result = $service->checkBudget(1, [
            'period' => 'monthly',
            'maxTokens' => '500000',
        ]);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Token budget exceeded', $result->reason);
    }

    #[Test]
    public function deniesWhenRequestBudgetExceeded(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->method('getCurrentUsage')->willReturn([
            'tokens_used' => 100,
            'cost_used' => 1.0,
            'requests_used' => 1000,
        ]);

        $service = new BudgetService($repository);
        $result = $service->checkBudget(1, [
            'period' => 'daily',
            'maxRequests' => '1000',
        ]);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Request budget exceeded', $result->reason);
    }

    #[Test]
    public function checksAllLimitsAndDeniesOnFirst(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->method('getCurrentUsage')->willReturn([
            'tokens_used' => 100,
            'cost_used' => 100.0, // over cost limit
            'requests_used' => 5,
        ]);

        $service = new BudgetService($repository);
        $result = $service->checkBudget(1, [
            'period' => 'monthly',
            'maxCost' => '50.00',
            'maxTokens' => '500000', // within
            'maxRequests' => '1000', // within
        ]);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Cost', $result->reason);
    }

    #[Test]
    public function recordUsageSkipsForInvalidUser(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->expects($this->never())->method('recordUsage');

        $service = new BudgetService($repository);
        $service->recordUsage(0, 'monthly', 100, 0.01);
    }

    #[Test]
    public function recordUsageDelegatesToRepository(): void
    {
        $repository = $this->createMock(UsageBudgetRepository::class);
        $repository->expects($this->once())
            ->method('recordUsage')
            ->with(42, 'monthly', 500, 0.05);

        $service = new BudgetService($repository);
        $service->recordUsage(42, 'monthly', 500, 0.05);
    }
}
