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

class UsageBudgetRepository
{
    private const TABLE = 'tx_aim_usage_budget';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Get or create the budget record for a user + period type.
     * Resets counters if the period has expired.
     *
     * @return array{tokens_used: int, cost_used: float, requests_used: int}
     */
    public function getCurrentUsage(int $userId, string $periodType): array
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $qb = $conn->createQueryBuilder();
        $qb->getRestrictions()->removeAll();

        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, Connection::PARAM_INT)),
                $qb->expr()->eq('period_type', $qb->createNamedParameter($periodType)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return $this->createInitialRecord($conn, $userId, $periodType);
        }

        if ($this->isPeriodExpired((int)$row['period_start'], $periodType)) {
            return $this->resetPeriod($conn, (int)$row['uid']);
        }

        return [
            'tokens_used' => (int)$row['tokens_used'],
            'cost_used' => (float)$row['cost_used'],
            'requests_used' => (int)$row['requests_used'],
        ];
    }

    /**
     * Increment usage counters after a successful request.
     */
    public function recordUsage(int $userId, string $periodType, int $tokens, float $cost): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        // Ensure record exists
        $this->getCurrentUsage($userId, $periodType);

        $conn->executeStatement(
            'UPDATE ' . self::TABLE . ' SET tokens_used = tokens_used + ?, cost_used = cost_used + ?, requests_used = requests_used + 1 WHERE user_id = ? AND period_type = ?',
            [$tokens, $cost, $userId, $periodType],
            [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_STR],
        );
    }

    /**
     * @return array{tokens_used: int, cost_used: float, requests_used: int}
     */
    private function createInitialRecord(Connection $conn, int $userId, string $periodType): array
    {
        $conn->insert(self::TABLE, [
            'user_id' => $userId,
            'period_type' => $periodType,
            'period_start' => time(),
            'tokens_used' => 0,
            'cost_used' => 0,
            'requests_used' => 0,
        ]);
        return ['tokens_used' => 0, 'cost_used' => 0.0, 'requests_used' => 0];
    }

    private function isPeriodExpired(int $periodStart, string $periodType): bool
    {
        return time() > $periodStart + $this->getPeriodDuration($periodType);
    }

    /**
     * @return array{tokens_used: int, cost_used: float, requests_used: int}
     */
    private function resetPeriod(Connection $conn, int $uid): array
    {
        $conn->update(self::TABLE, [
            'period_start' => time(),
            'tokens_used' => 0,
            'cost_used' => 0,
            'requests_used' => 0,
        ], [
            'uid' => $uid,
        ]);
        return ['tokens_used' => 0, 'cost_used' => 0.0, 'requests_used' => 0];
    }

    private function getPeriodDuration(string $periodType): int
    {
        return match ($periodType) {
            'daily' => 86400,
            'weekly' => 604800,
            default => 2592000, // 30 days
        };
    }
}
