<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Crypto;

use B13\Aim\Exception\ApiKeyEncryptionException;
use TYPO3\CMS\Core\Crypto\Cipher\CipherDecryptionFailedException;
use TYPO3\CMS\Core\Crypto\Cipher\CipherException;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;
use TYPO3\CMS\Core\Crypto\Cipher\CipherValue;
use TYPO3\CMS\Core\Crypto\Cipher\KeyFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Encrypts and decrypts AiM provider API keys stored in the database.
 *
 * On TYPO3 v14+ this delegates to the core CipherService (XChaCha20-Poly1305
 * AEAD, key derived via sodium_crypto_kdf_derive_from_key from SYS/encryptionKey).
 * On v12/v13 — where CipherService does not exist yet — we use a libsodium
 * secretbox implementation (XSalsa20-Poly1305) with the same SYS/encryptionKey
 * as input, derived through sodium_crypto_generichash.
 *
 * Wire format selects the path on decrypt:
 *   aim:enc:v1: ... — local secretbox payload (used on v12/v13)
 *   aim:enc:v2: ... — TYPO3 CipherService payload (used on v14+)
 *
 * Values without either prefix are treated as legacy plaintext and returned
 * unchanged, so the upgrade wizard can migrate them in-place.
 */
final class ApiKeyEncryption
{
    public const PREFIX_V1 = 'aim:enc:v1:';
    public const PREFIX_V2 = 'aim:enc:v2:';
    public const PREFIX_ANY = 'aim:enc:';

    private const KEY_DOMAIN = 'aim:apikey:v1';

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || $this->isEncrypted($plaintext) || $this->isEndpointUrl($plaintext)) {
            return $plaintext;
        }

        if ($this->coreCipherAvailable()) {
            return self::PREFIX_V2 . $this->encryptViaCore($plaintext);
        }
        return self::PREFIX_V1 . $this->encryptViaSecretbox($plaintext);
    }

    /**
     * The api_key column doubles as an endpoint URL for providers that
     * expose a local HTTP service (Ollama, LM Studio, OpenAI-compatible
     * proxies). Those values aren't secrets and shouldn't be encrypted.
     */
    public function isEndpointUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    public function decrypt(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        if (str_starts_with($value, self::PREFIX_V2)) {
            return $this->decryptViaCore(substr($value, strlen(self::PREFIX_V2)));
        }
        if (str_starts_with($value, self::PREFIX_V1)) {
            return $this->decryptViaSecretbox(substr($value, strlen(self::PREFIX_V1)));
        }
        return $value;
    }

    /**
     * Decrypts a value using a system encryption key other than the one
     * currently in $TYPO3_CONF_VARS — needed by the rotation command after
     * SYS/encryptionKey has changed but stored ciphertexts still use the old
     * derivation.
     *
     * Both decryption paths (v1 secretbox and v2 CipherService) read the
     * system key from globals, so we swap it temporarily and restore it via
     * try/finally. The swap is local to this call.
     */
    public function decryptWithSystemKey(string $value, string $systemKey): string
    {
        if ($value === '' || !$this->isEncrypted($value)) {
            return $value;
        }

        $previous = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $systemKey;
        try {
            return $this->decrypt($value);
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $previous;
        }
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX_ANY);
    }

    private function coreCipherAvailable(): bool
    {
        return class_exists(CipherService::class) && class_exists(KeyFactory::class);
    }

    private function encryptViaCore(string $plaintext): string
    {
        $keyFactory = GeneralUtility::makeInstance(KeyFactory::class);
        $cipher = GeneralUtility::makeInstance(CipherService::class);
        $sharedKey = $keyFactory->deriveSharedKeyFromEncryptionKey(self::class);
        return $cipher->encrypt($plaintext, $sharedKey)->encode();
    }

    private function decryptViaCore(string $payload): string
    {
        try {
            $keyFactory = GeneralUtility::makeInstance(KeyFactory::class);
            $cipher = GeneralUtility::makeInstance(CipherService::class);
            $sharedKey = $keyFactory->deriveSharedKeyFromEncryptionKey(self::class);
            return $cipher->decrypt(CipherValue::fromSerialized($payload), $sharedKey);
        } catch (CipherDecryptionFailedException | CipherException $e) {
            throw new ApiKeyEncryptionException(
                'AiM API key could not be decrypted via core CipherService: ' . $e->getMessage(),
                1773874410,
                $e,
            );
        }
    }

    private function encryptViaSecretbox(string $plaintext): string
    {
        $key = $this->deriveSecretboxKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return base64_encode($nonce . $ciphertext);
    }

    private function decryptViaSecretbox(string $payload): string
    {
        $bytes = base64_decode($payload, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new ApiKeyEncryptionException('AiM API key ciphertext is malformed.', 1773874400);
        }

        $nonce = substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveSecretboxKey();
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);

        if ($plaintext === false) {
            throw new ApiKeyEncryptionException(
                'AiM API key could not be decrypted. The system encryption key may have changed.',
                1773874401,
            );
        }

        return $plaintext;
    }

    private function deriveSecretboxKey(): string
    {
        $systemKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($systemKey === '') {
            throw new ApiKeyEncryptionException(
                'AiM API key encryption requires $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'encryptionKey\'] to be set.',
                1773874402,
            );
        }

        return sodium_crypto_generichash(
            self::KEY_DOMAIN . "\0" . $systemKey,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        );
    }
}
