<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Hooks;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ensure there is only one default provider set
 */
class DefaultProviderHook
{
    private const TABLE = 'tx_aim_configuration';

    public function processDatamap_afterDatabaseOperations($status, $table, $id, $fieldArray, DataHandler $dataHandler): void
    {
        /**
         * Take action only on
         *   - tx_aim_configuration table
         *   - live workspace
         *   - not bulk importing things via CLI
         */
        if ($table !== self::TABLE
            || $dataHandler->BE_USER->workspace > 0
            || $dataHandler->isImporting
        ) {
            return;
        }

        $id = (int)($dataHandler->substNEWwithIDs[$id] ?? $id);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);

        $connection->beginTransaction();
        try {
            // If default is explicitly set to TRUE, unset all other defaults
            if (isset($fieldArray['default']) && (int)$fieldArray['default'] === 1) {
                $connection->executeStatement(
                    'UPDATE ' . self::TABLE . ' SET `default` = 0 WHERE `uid` != :uid',
                    ['uid' => $id],
                    ['uid' => Connection::PARAM_INT],
                );
                $connection->commit();
                return;
            }

            // If this is a new record and there are no other records, set it as default
            if ($status === 'new') {
                $queryBuilder = $connection->createQueryBuilder();
                $count = (int)$queryBuilder
                    ->count('uid')
                    ->from(self::TABLE)
                    ->where($queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($id, Connection::PARAM_INT)))
                    ->executeQuery()
                    ->fetchOne();

                if ($count === 0) {
                    $connection->update(
                        self::TABLE,
                        ['default' => 1],
                        ['uid' => $id],
                        [Connection::PARAM_INT, Connection::PARAM_INT],
                    );
                }
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
