<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Crypto;

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Exception\ApiKeyEncryptionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\Cipher\CipherService;

final class ApiKeyEncryptionTest extends TestCase
{
    private string $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $this->originalKey;
        parent::tearDown();
    }

    #[Test]
    public function encryptDecryptRoundTrip(): void
    {
        $service = new ApiKeyEncryption();
        $plaintext = 'sk-proj-abc123-very-secret';

        $encrypted = $service->encrypt($plaintext);

        self::assertNotSame($plaintext, $encrypted);
        self::assertTrue($service->isEncrypted($encrypted));
        self::assertSame($plaintext, $service->decrypt($encrypted));
    }

    #[Test]
    public function encryptPicksV2OnTypo3V14(): void
    {
        if (!class_exists(CipherService::class)) {
            self::markTestSkipped('TYPO3 CipherService not available — only present on v14+.');
        }
        $encrypted = (new ApiKeyEncryption())->encrypt('sk-test');
        self::assertStringStartsWith(ApiKeyEncryption::PREFIX_V2, $encrypted);
    }

    #[Test]
    public function decryptOfV1PayloadStillWorksOnV14(): void
    {
        // A value persisted by v12/v13 must remain readable after upgrade.
        // We build a v1 payload directly so the test is independent of which
        // path encrypt() currently picks.
        $v1 = $this->makeV1Payload('sk-from-old-typo3');
        self::assertSame('sk-from-old-typo3', (new ApiKeyEncryption())->decrypt($v1));
    }

    #[Test]
    public function encryptLeavesEndpointUrlsUntouched(): void
    {
        $service = new ApiKeyEncryption();
        $endpoint = 'http://host.docker.internal:11434';

        $result = $service->encrypt($endpoint);

        self::assertSame($endpoint, $result);
        self::assertFalse($service->isEncrypted($result));
    }

    #[Test]
    public function encryptLeavesHttpsEndpointUrlsUntouched(): void
    {
        $service = new ApiKeyEncryption();
        $endpoint = 'https://my-self-hosted-llm.example.com:8443';

        self::assertSame($endpoint, $service->encrypt($endpoint));
    }

    #[Test]
    public function encryptIsIdempotent(): void
    {
        $service = new ApiKeyEncryption();
        $encrypted = $service->encrypt('sk-test');

        self::assertSame($encrypted, $service->encrypt($encrypted));
    }

    #[Test]
    public function encryptOfEmptyStringStaysEmpty(): void
    {
        $service = new ApiKeyEncryption();
        self::assertSame('', $service->encrypt(''));
    }

    #[Test]
    public function decryptOfLegacyPlaintextReturnsInputUnchanged(): void
    {
        $service = new ApiKeyEncryption();
        self::assertSame('sk-legacy-plaintext', $service->decrypt('sk-legacy-plaintext'));
    }

    #[Test]
    public function encryptUsesFreshNonceEachTime(): void
    {
        $service = new ApiKeyEncryption();
        $first = $service->encrypt('sk-same-input');
        $second = $service->encrypt('sk-same-input');

        self::assertNotSame($first, $second);
        self::assertSame('sk-same-input', $service->decrypt($first));
        self::assertSame('sk-same-input', $service->decrypt($second));
    }

    #[Test]
    public function decryptFailsWhenSystemKeyChanged(): void
    {
        $service = new ApiKeyEncryption();
        $encrypted = $service->encrypt('sk-test');

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('b', 96);

        $this->expectException(ApiKeyEncryptionException::class);
        $service->decrypt($encrypted);
    }

    #[Test]
    public function isEncryptedDetectsBothPrefixes(): void
    {
        $service = new ApiKeyEncryption();
        self::assertFalse($service->isEncrypted('sk-plaintext'));
        self::assertTrue($service->isEncrypted(ApiKeyEncryption::PREFIX_V1 . 'whatever'));
        self::assertTrue($service->isEncrypted(ApiKeyEncryption::PREFIX_V2 . 'whatever'));
    }

    #[Test]
    public function encryptFailsWithoutSystemEncryptionKeyOnV1Path(): void
    {
        if (class_exists(CipherService::class)) {
            self::markTestSkipped('On v14 the missing-key error is raised by core, not by us.');
        }
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';

        $this->expectException(ApiKeyEncryptionException::class);
        $this->expectExceptionCode(1773874402);
        (new ApiKeyEncryption())->encrypt('sk-test');
    }

    /**
     * Builds a v1 payload by replicating the libsodium secretbox path,
     * so the test does not depend on which encryption path the service
     * picks at runtime.
     */
    private function makeV1Payload(string $plaintext): string
    {
        $key = sodium_crypto_generichash(
            "aim:apikey:v1\0" . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'],
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        );
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return ApiKeyEncryption::PREFIX_V1 . base64_encode($nonce . $ciphertext);
    }
}
