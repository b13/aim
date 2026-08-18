<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend\FormDataProvider;

use B13\Aim\Crypto\ApiKeyEncryption;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Never sends an already-encrypted api_key back to the browser. The
 * field renders blank instead, switched to a masked type='password' input,
 * with a placeholder that only says a key IS configured, never any part of it.
 *
 * This field doubles as the endpoint URL for local/self-hosted providers
 * (Ollama, LM Studio, ...). ApiKeyEncryption deliberately never encrypts an
 * http(s):// value, since it isn't a secret. That value is left completely
 * untouched here: shown as normal, visible, editable text, same as any
 * other field, since there is nothing to hide.
 *
 * A blank resubmission on an existing record with a real key is
 * deliberately NOT treated as "clear the key". EncryptApiKey's own
 * DataHandler hook drops the field from the update entirely in that case,
 * so the previously stored (encrypted) value survives untouched. Only a
 * non-empty submission replaces it.
 */
final class HideApiKey implements FormDataProviderInterface
{
    public function __construct(private readonly ApiKeyEncryption $encryption) {}

    public function addData(array $result): array
    {
        if (($result['tableName'] ?? '') !== 'tx_aim_configuration') {
            return $result;
        }

        $storedValue = (string)($result['databaseRow']['api_key'] ?? '');
        if ($storedValue === '' || !$this->encryption->isEncrypted($storedValue)) {
            // Nothing stored, or a non-secret endpoint URL, nothing to hide.
            return $result;
        }

        $result['databaseRow']['api_key'] = '';
        $result['processedTca']['columns']['api_key']['config']['type'] = 'password';
        $result['processedTca']['columns']['api_key']['config']['placeholder'] = $this->getLanguageService()->sL(
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.placeholder.configured'
        );

        return $result;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
