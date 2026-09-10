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
use B13\Aim\Provider\EndpointCredential;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Never sends an already-encrypted api_key back to the browser. The
 * field renders blank instead, switched to a masked type='password' input,
 * with a placeholder that only says a key IS configured, never any part of it.
 *
 * The endpoint has its own column, and api_key is always encrypted. On a row
 * the split migration has not reached, api_key still holds the endpoint URL,
 * so that value is copied into the endpoint field to stay visible, minus
 * any inline credential.
 *
 * This provider must run before TcaSelectItems, see its registration in
 * ext_localconf.php: the model field's itemsProcFunc reads api_key to
 * authenticate model discovery, and would otherwise see the ciphertext.
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
        if ($storedValue === '') {
            // No stored key, so there is nothing for the "remove the stored
            // key" control (ClearApiKey) to remove. Core renders no button
            // for a control it is not given, so dropping it here is enough.
            unset($result['processedTca']['columns']['api_key']['config']['fieldControl']['aimClearApiKey']);
            return $result;
        }

        // Before the split migration runs, api_key still holds the endpoint URL
        // and the endpoint column is empty. Surface it in the field it belongs
        // to now, or blanking api_key below would leave it nowhere to be seen.
        // Any inline credential is left out: the endpoint field is plain text,
        // and putting a password back into a visible input is what blanking
        // api_key below exists to prevent.
        $isLegacyEndpoint = $this->encryption->isEndpointUrl($storedValue);
        if ($isLegacyEndpoint && (string)($result['databaseRow']['endpoint'] ?? '') === '') {
            $result['databaseRow']['endpoint'] = EndpointCredential::forDisplay($storedValue);
        }

        // The field is type => 'password' in TCA, so masking is not this provider's
        // job. What is left is blanking the stored value so it never round-trips into
        // the DOM, which applies to a legacy plaintext key as much as to a ciphertext.
        $result['databaseRow']['api_key'] = '';
        if (!$isLegacyEndpoint) {
            $result['processedTca']['columns']['api_key']['config']['placeholder'] = $this->getLanguageService()->sL(
                'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.placeholder.configured'
            );
        }

        return $result;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
