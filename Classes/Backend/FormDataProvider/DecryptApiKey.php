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

/**
 * Decrypts the api_key value before it is rendered in the backend form.
 *
 * Admins editing a tx_aim_configuration record see the plaintext key and
 * can verify or replace it. On submit, the DataHandler hook encrypts the
 * value again. If the admin re-saves the record without touching api_key,
 * the form re-sends plaintext, which the hook re-encrypts — the value on
 * disk stays encrypted throughout.
 */
final class DecryptApiKey implements FormDataProviderInterface
{
    public function __construct(private readonly ApiKeyEncryption $encryption) {}

    public function addData(array $result): array
    {
        if (($result['tableName'] ?? '') !== 'tx_aim_configuration') {
            return $result;
        }

        $value = (string)($result['databaseRow']['api_key'] ?? '');
        if ($value === '' || !$this->encryption->isEncrypted($value)) {
            return $result;
        }

        $result['databaseRow']['api_key'] = $this->encryption->decrypt($value);
        return $result;
    }
}
