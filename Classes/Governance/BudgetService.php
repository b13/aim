<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Governance;

use B13\Aim\Domain\Repository\UsageBudgetRepository;

/**
 * Checks and records AI usage against per-user budget limits.
 *
 * Limits are configured via UserTSconfig:
 *   aim.budget.period = monthly
 *   aim.budget.maxCost = 50.00
 *   aim.budget.maxTokens = 500000
 *   aim.budget.maxRequests = 1000
 */
class BudgetService
{
    public function __construct(
        private readonly UsageBudgetRepository $repository,
    ) {}

    /**
     * Check if the user is within their budget limits.
     *
     * @param array<string, mixed> $tsConfig The user's merged aim.budget.* TSconfig
     */
    public function checkBudget(int $userId, array $tsConfig): BudgetCheckResult
    {
        $period = (string)($tsConfig['period'] ?? 'monthly');
        $maxCost = (float)($tsConfig['maxCost'] ?? 0);
        $maxTokens = (int)($tsConfig['maxTokens'] ?? 0);
        $maxRequests = (int)($tsConfig['maxRequests'] ?? 0);

        // No limits configured — allow
        if ($maxCost <= 0 && $maxTokens <= 0 && $maxRequests <= 0) {
            return BudgetCheckResult::allowed();
        }

        $usage = $this->repository->getCurrentUsage($userId, $period);

        if ($maxCost > 0 && $usage['cost_used'] >= $maxCost) {
            return BudgetCheckResult::denied(sprintf(
                'Cost budget exceeded: $%.4f used of $%.4f %s limit.',
                $usage['cost_used'],
                $maxCost,
                $period,
            ));
        }

        if ($maxTokens > 0 && $usage['tokens_used'] >= $maxTokens) {
            return BudgetCheckResult::denied(sprintf(
                'Token budget exceeded: %s used of %s %s limit.',
                number_format($usage['tokens_used']),
                number_format($maxTokens),
                $period,
            ));
        }

        if ($maxRequests > 0 && $usage['requests_used'] >= $maxRequests) {
            return BudgetCheckResult::denied(sprintf(
                'Request budget exceeded: %d used of %d %s limit.',
                $usage['requests_used'],
                $maxRequests,
                $period,
            ));
        }

        return BudgetCheckResult::allowed();
    }

    /**
     * Record usage after a successful request.
     */
    public function recordUsage(int $userId, string $period, int $tokens, float $cost): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->repository->recordUsage($userId, $period, $tokens, $cost);
    }
}
