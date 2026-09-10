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
use B13\Aim\Provider\EndpointCredential;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Moves endpoint URLs out of api_key and into their own column.
 *
 * A plain endpoint URL moves to `endpoint`; a credential-bearing URL
 * (https://user:token@host) is split into an encrypted credential plus a bare
 * URL; anything else in api_key is encrypted.
 */
#[UpgradeWizard('aimSplitEndpointFromApiKey')]
final class SplitEndpointFromApiKeyUpgrade implements UpgradeWizardInterface
{
    private const TABLE = 'tx_aim_configuration';

    public function __construct(
        private readonly ApiKeyEncryption $encryption,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return '[AiM] Split endpoint URLs out of the API key column';
    }

    public function getDescription(): string
    {
        return 'Moves endpoint URLs from tx_aim_configuration.api_key into the new endpoint column, '
            . 'splits credential-bearing proxy URLs into an encrypted credential plus a bare URL, and '
            . 'encrypts anything left in api_key. Endpoint URLs were previously stored unencrypted, '
            . 'including the ones carrying a credential.';
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * Core builds the wizard list by calling this before it enforces
     * DatabaseUpdatedPrerequisite, so it has to answer rather than throw when
     * the column does not exist yet. It also must not encrypt: that would burn
     * a nonce per call and, on an install with no encryption key, throw out of
     * the wizard list.
     *
     * A missing column answers true, not false. UpgradeWizardRunCommand marks
     * every wizard that answers false as done and never revisits it, and it
     * collects all wizards before it fulfils the prerequisite, so answering
     * false here would retire this migration permanently on a `upgrade:run`
     * issued before the schema update. The prerequisite means executeUpdate()
     * only ever runs with the column in place.
     */
    public function updateNecessary(): bool
    {
        if (!$this->endpointColumnExists()) {
            return true;
        }

        foreach ($this->findRows() as $row) {
            if ($this->needsUpdate($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The predicate resolveUpdate() answers to, without encrypting anything.
     * Every plaintext value left in api_key needs work: a credential to
     * encrypt, a URL to move out, or a leftover URL to clear once an endpoint
     * has been configured by hand. That last case used to answer false while
     * resolveUpdate() would still have taken a plaintext credential out of it.
     *
     * @param array{uid: int, api_key: string, endpoint: string} $row
     */
    private function needsUpdate(array $row): bool
    {
        return $row['api_key'] !== '' && !$this->encryption->isEncrypted($row['api_key']);
    }

    private function endpointColumnExists(): bool
    {
        try {
            $columns = $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->createSchemaManager()
                ->listTableColumns(self::TABLE);
        } catch (\Throwable) {
            return false;
        }

        return isset($columns['endpoint']);
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        foreach ($this->findRows() as $row) {
            $update = $this->resolveUpdate($row);
            if ($update === []) {
                continue;
            }

            $connection->update(
                self::TABLE,
                $update,
                ['uid' => $row['uid']],
                array_fill_keys(array_keys($update), Connection::PARAM_STR),
            );
        }

        return true;
    }

    /**
     * @param array{uid: int, api_key: string, endpoint: string} $row
     * @return array<string, string>
     */
    private function resolveUpdate(array $row): array
    {
        $apiKey = $row['api_key'];
        if ($apiKey === '' || $this->encryption->isEncrypted($apiKey)) {
            return [];
        }

        if (!$this->encryption->isEndpointUrl($apiKey)) {
            return ['api_key' => $this->encryption->encrypt($apiKey)];
        }

        $credential = EndpointCredential::split($apiKey);

        // An endpoint configured by hand after the upgrade wins: the value in
        // api_key is then a leftover, and overwriting the endpoint with it would
        // silently repoint the configuration at the old host.
        if ($row['endpoint'] !== '') {
            return $credential === null
                ? ['api_key' => '']
                : ['api_key' => $this->encryption->encrypt($credential['secret'])];
        }

        if ($credential === null) {
            return ['api_key' => '', 'endpoint' => $apiKey];
        }

        return [
            'api_key' => $this->encryption->encrypt($credential['secret']),
            'endpoint' => $credential['url'],
        ];
    }

    /**
     * @return list<array{uid: int, api_key: string, endpoint: string}>
     */
    private function findRows(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select('uid', 'api_key', 'endpoint')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'uid' => (int)$row['uid'],
                'api_key' => (string)$row['api_key'],
                'endpoint' => (string)$row['endpoint'],
            ],
            $rows,
        );
    }
}
