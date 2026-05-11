<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Updates;

use B13\Aim\Crypto\ApiKeyEncryption;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Encrypts plaintext api_key values
 *
 * Idempotent: rows whose api_key already carries the encryption prefix
 * are skipped, and endpoint URLs (Ollama, LM Studio, …) are left as
 * plaintext, so it is safe to re-run.
 */
#[UpgradeWizard('aimEncryptApiKeys')]
final class EncryptApiKeysUpgrade implements UpgradeWizardInterface
{
    private const TABLE = 'tx_aim_configuration';

    public function __construct(
        private readonly ApiKeyEncryption $encryption,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return '[AiM] Encrypt stored provider API keys';
    }

    public function getDescription(): string
    {
        return 'Encrypts existing plaintext API keys in tx_aim_configuration using libsodium '
            . '(XSalsa20-Poly1305) with a key derived from $TYPO3_CONF_VARS[SYS][encryptionKey]. '
            . 'Already-encrypted rows are skipped.';
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function updateNecessary(): bool
    {
        return $this->findRowsToEncrypt() !== [];
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        foreach ($this->findRowsToEncrypt() as $row) {
            $connection->update(
                self::TABLE,
                ['api_key' => $this->encryption->encrypt((string)$row['api_key'])],
                ['uid' => (int)$row['uid']],
                ['api_key' => Connection::PARAM_STR],
            );
        }

        return true;
    }

    /**
     * Returns plaintext rows that hold a real API key (not an endpoint URL).
     *
     * @return list<array{uid: int, api_key: string}>
     */
    private function findRowsToEncrypt(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select('uid', 'api_key')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->neq('api_key', $qb->createNamedParameter('')),
                $qb->expr()->notLike(
                    'api_key',
                    $qb->createNamedParameter(ApiKeyEncryption::PREFIX_ANY . '%'),
                ),
                $qb->expr()->notLike('api_key', $qb->createNamedParameter('http://%')),
                $qb->expr()->notLike('api_key', $qb->createNamedParameter('https://%')),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => ['uid' => (int)$row['uid'], 'api_key' => (string)$row['api_key']],
            $rows,
        );
    }
}
