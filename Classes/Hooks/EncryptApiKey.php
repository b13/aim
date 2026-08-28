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
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Encrypts AiM provider API keys before they are written to the database.
 *
 * Runs in processDatamap_postProcessFieldArray so the encrypted value is
 * what DataHandler persists. Idempotent: already-encrypted values pass
 * through unchanged, which means re-saving an unchanged row does not
 * double-encrypt the value.
 * Also runs in processDatamap_preProcessFieldArray: on updates, DataHandler
 * captures the sys_history diff befire the post-process hook, so the
 * plaintext key would otherwise end up in the record history.
 */
final class EncryptApiKey
{
    private const TABLE = 'tx_aim_configuration';

    public function __construct(private readonly ApiKeyEncryption $encryption) {}

    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        $id,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }

        $value = (string)($incomingFieldArray['api_key'] ?? '');
        if ($value === '') {
            return;
        }

        $incomingFieldArray['api_key'] = $this->encryption->encrypt($value);
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
            if ($status === 'update') {
                unset($fieldArray['api_key']);
            }
            return;
        }

        $fieldArray['api_key'] = $this->encryption->encrypt($value);
    }
}
