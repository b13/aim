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

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Provider\EndpointCredential;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * Encrypts AiM provider API keys before they are written to the database.
 *
 * Encryption happens in processDatamap_preProcessFieldArray, because on an
 * update DataHandler captures the sys_history diff in
 * compareFieldArrayWithCurrentAndUnset() before it calls the post-process
 * hook - encrypting only there would leave the plaintext key in the record
 * history even though the column itself is encrypted. Inserts were never
 * affected, since insertDB() writes the history entry after the hook.
 *
 * processDatamap_postProcessFieldArray stays for the "empty means keep the
 * stored key" handling, and re-encrypts as a safety net for callers that
 * bypass the pre-process stage. Both are idempotent: encrypt() passes
 * already-encrypted values through unchanged, so nothing is encrypted twice.
 */
final class EncryptApiKey
{
    /**
     * Written into the api_key field by the ClearApiKey field control to mean
     * "remove the stored credential", which a blank submission cannot express:
     * a stored key is never shown, so blank is the normal state and has to mean
     * "leave it alone".
     */
    public const CLEAR_MARKER = '__aim_clear_api_key__';

    private const TABLE = 'tx_aim_configuration';

    /**
     * uids whose credential this run is clearing on purpose, so the
     * "empty means keep it" rule in the post-process hook stands aside.
     *
     * The marker is turned into an empty value in the pre-process hook rather
     * than the post-process one, because DataHandler computes the sys_history
     * diff in between (compareFieldArrayWithCurrentAndUnset, called just before
     * the post hook). Clearing later would leave the deletion out of the record
     * history. DataHandler builds its hook objects once per run and uses the
     * same instance for both, so this survives from one to the other.
     *
     * @var array<int, true>
     */
    private array $clearing = [];

    public function __construct(
        private readonly ApiKeyEncryption $encryption,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        $id,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }

        // Before the split! A marker sitting in api_key would otherwise be taken
        // for an explicitly typed credential and win over one in the endpoint URL.
        if ((string)($incomingFieldArray['api_key'] ?? '') === self::CLEAR_MARKER) {
            $incomingFieldArray['api_key'] = '';
            if (is_numeric($id)) {
                $this->clearing[(int)$id] = true;
            }
        }

        $this->splitCredentialOutOfEndpoint($incomingFieldArray, $id, $dataHandler);

        $value = (string)($incomingFieldArray['api_key'] ?? '');
        if ($value === '') {
            return;
        }

        $incomingFieldArray['api_key'] = $this->encryption->encrypt($value);
    }

    /**
     * @param array<string, mixed> $incomingFieldArray
     */
    private function splitCredentialOutOfEndpoint(array &$incomingFieldArray, $id, DataHandler $dataHandler): void
    {
        $endpoint = (string)($incomingFieldArray['endpoint'] ?? '');
        if ($endpoint === '') {
            return;
        }

        $credential = EndpointCredential::split($endpoint);
        if ($credential === null) {
            return;
        }

        $incomingFieldArray['endpoint'] = $credential['url'];
        // An explicitly submitted api_key wins: the editor typed it in the field
        // meant for it, so the URL is not the place to take one from.
        $typed = (string)($incomingFieldArray['api_key'] ?? '');
        if ($typed === '') {
            $incomingFieldArray['api_key'] = $credential['secret'];
            return;
        }

        // Both were filled in and they disagree, so one of them is being
        // discarded. Silently picking the API key field has been the behaviour
        // all along, but an editor who left an old password in the URL has no
        // way to notice which one survived.
        if ($typed !== $credential['secret']) {
            $dataHandler->log(
                self::TABLE,
                $id,
                SystemLogDatabaseAction::UPDATE,
                // The 4th argument is the former $recpid, which v13 and v14
                // declare as an ignored null and v12 leaves untyped.
                null,
                SystemLogErrorClassification::WARNING,
                'The endpoint URL carried a password and the API key field was filled in too. '
                . 'The API key field was used and the password in the URL was discarded.',
            );
        }
    }

    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        $id,
        array &$fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE || !array_key_exists('api_key', $fieldArray)) {
            return;
        }

        $value = (string)$fieldArray['api_key'];
        if ($value === '') {
            // HideApiKey (FormDataProvider) never pre-fills this field with
            // the existing key, so an empty submission on an update means
            // "leave it untouched", not "clear it". Drop it from the field
            // array entirely so DataHandler doesn't overwrite the column,
            // and the previously encrypted value on disk survives.
            // Unless the ClearApiKey control asked for it, which is the one way
            // to mean "remove it". The pre-process hook recorded that.
            if ($status === 'update' && !isset($this->clearing[(int)$id])) {
                // Only heal when this save carries the endpoint field, the same
                // condition warnAboutKeptCredential() applies. A columnsOnly
                // edit of an unrelated field would otherwise clear the legacy
                // URL out of api_key while endpoint stays empty, and the
                // configuration loses its endpoint altogether.
                $healed = array_key_exists('endpoint', $fieldArray)
                    ? $this->healStoredLegacyEndpoint((int)$id)
                    : null;
                if ($healed !== null) {
                    $fieldArray['api_key'] = $healed;

                    return;
                }
                // Reached only when a stored key survives this save, which is
                // the one case worth reporting.
                $this->warnAboutKeptCredential($fieldArray, (int)$id, $dataHandler);
                unset($fieldArray['api_key']);
            }
            unset($this->clearing[(int)$id]);
            return;
        }

        $fieldArray['api_key'] = $this->encryption->encrypt($value);
    }

    /**
     * Takes a leftover endpoint URL out of a stored api_key on any save.
     *
     * Returns the value to store, or null when there is nothing to heal.
     *
     * @return array{endpoint: string, api_key: string}|null
     */
    private function storedRow(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $row = $qb->select('endpoint', 'api_key')
                ->from(self::TABLE)
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return null;
        }

        return $row === false
            ? null
            : ['endpoint' => (string)$row['endpoint'], 'api_key' => (string)$row['api_key']];
    }

    /**
     * Repointing a configuration at a different host while a credential stays
     * stored means the old credential is sent to the new host: harmless for a
     * local Ollama, not harmless for a reconfigured gateway. Nothing here can
     * tell which was meant, so it reports rather than decides.
     *
     * @param array<string, mixed> $fieldArray
     */
    private function warnAboutKeptCredential(array $fieldArray, int $uid, DataHandler $dataHandler): void
    {
        if (!array_key_exists('endpoint', $fieldArray)) {
            return;
        }

        $stored = $this->storedRow($uid);
        if ($stored === null || $stored['api_key'] === '') {
            return;
        }

        // No legacy check needed: the only caller reaches this after
        // healStoredLegacyEndpoint() returned null, which it does exactly when
        // the stored value is empty or is not an endpoint URL. A migrating
        // legacy row therefore never gets here, so it cannot false-alarm.
        if ((string)$fieldArray['endpoint'] === $stored['endpoint']) {
            return;
        }

        $dataHandler->log(
            self::TABLE,
            $uid,
            SystemLogDatabaseAction::UPDATE,
            null,
            SystemLogErrorClassification::WARNING,
            'The endpoint changed but the stored API key was kept, so it will be sent to the new host. '
            . 'Enter a new key, or use the button next to the field to remove the stored one.',
        );
    }

    private function healStoredLegacyEndpoint(int $uid): ?string
    {
        $stored = $this->storedRow($uid)['api_key'] ?? '';
        if ($stored === '' || !$this->encryption->isEndpointUrl($stored)) {
            return null;
        }
        $credential = EndpointCredential::split($stored);
        return $credential === null ? '' : $this->encryption->encrypt($credential['secret']);
    }
}
