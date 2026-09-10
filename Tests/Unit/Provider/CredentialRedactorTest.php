<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Provider;

use B13\Aim\Provider\CredentialRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CredentialRedactorTest extends TestCase
{
    private const SECRET = 'sk-proj-A1b2C3d4E5f6G7h8';

    /**
     * @return array<string, array{0: string}>
     */
    public static function encodingsTheOldExactMatchMissed(): array
    {
        return [
            'raw' => ['Request failed with key ' . self::SECRET . ' rejected'],
            'url encoded' => ['GET /v1/models?api_key=' . rawurlencode(self::SECRET) . ' returned 401'],
            'json escaped' => ['Body: {"api_key":"' . self::SECRET . '"} was rejected'],
            'line wrapped' => ["Authorization:\nBearer " . self::SECRET],
        ];
    }

    #[Test]
    #[DataProvider('encodingsTheOldExactMatchMissed')]
    public function theConfiguredKeyIsRemovedInEveryEncodingItCanArriveIn(string $message): void
    {
        self::assertStringNotContainsString(
            self::SECRET,
            (new CredentialRedactor())->redact($message, self::SECRET),
        );
    }

    #[Test]
    public function aCredentialBearingEndpointUrlLosesOnlyItsPassword(): void
    {
        $endpoint = 'https://gateway.example.com/v1';
        $configured = 'https://svc:s3cr3t-token-value@gateway.example.com/v1';

        $redacted = (new CredentialRedactor())->redact(
            'Connection to ' . $configured . ' timed out',
            $configured,
        );

        self::assertStringNotContainsString('s3cr3t-token-value', $redacted);
        self::assertStringContainsString('gateway.example.com', $redacted, 'The host has to survive, or the message stops being diagnostic.');
        self::assertStringNotContainsString($endpoint . '"', $redacted);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function credentialsFromElsewhere(): array
    {
        return [
            'openai style' => ['upstream said sk-abcdefgh12345678 is invalid', 'sk-abcdefgh12345678'],
            'anthropic style' => ['key sk-ant-api03-ZZZyyyXXX111 revoked', 'sk-ant-api03-ZZZyyyXXX111'],
            'google style' => ['AIzaSyD-1234567890abcdef rejected', 'AIzaSyD-1234567890abcdef'],
            'hugging face style' => ['token hf_AbCdEfGhIjKlMnOp expired', 'hf_AbCdEfGhIjKlMnOp'],
            'bearer header' => ['sent Authorization: Bearer abcdef1234567890', 'abcdef1234567890'],
            'query parameter' => ['GET /v1/chat?access_token=zyxwvu987654 failed', 'zyxwvu987654'],
        ];
    }

    #[Test]
    #[DataProvider('credentialsFromElsewhere')]
    public function credentialsAreRemovedEvenWhenTheyAreNotTheConfiguredOne(string $message, string $secret): void
    {
        self::assertStringNotContainsString($secret, (new CredentialRedactor())->redact($message, ''));
    }

    #[Test]
    public function anOrdinaryMessageIsLeftAlone(): void
    {
        $message = 'Model gpt-4o is not available for this account (request id req-12345)';

        self::assertSame($message, (new CredentialRedactor())->redact($message, ''));
    }

    #[Test]
    public function aShortValueIsNotUsedAsANeedle(): void
    {
        // Redacting a 3-character "secret" would shred unrelated text.
        $message = 'The model responded with an error';

        self::assertSame($message, (new CredentialRedactor())->redact($message, 'abc'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keysUsedAsTheUserName(): array
    {
        return [
            // The Stripe-style shape: the key is the basic-auth user name and
            // the password is deliberately empty. aim cannot take such a key
            // out of the endpoint column, so at the very least it must not
            // repeat it in a message that gets stored and shown.
            'explicit empty password' => ['POST https://sk_live_51H8xKfAbCdEfGh:@api.example.com/v1 returned 402', 'sk_live_51H8xKfAbCdEfGh'],
            'no password at all' => ['POST https://sk_live_51H8xKfAbCdEfGh@api.example.com/v1 returned 402', 'sk_live_51H8xKfAbCdEfGh'],
            'not a vendor prefix' => ['connect failed: http://9f8e7d6c5b4a3f2e1d@gateway.internal:8443/v1', '9f8e7d6c5b4a3f2e1d'],
        ];
    }

    #[Test]
    #[DataProvider('keysUsedAsTheUserName')]
    public function aKeyUsedAsTheUserNameIsRemovedToo(string $message, string $key): void
    {
        $redacted = (new CredentialRedactor())->redact($message, '');

        self::assertStringNotContainsString($key, $redacted);
        self::assertMatchesRegularExpression(
            '/(api\.example\.com|gateway\.internal)/',
            $redacted,
            'The host has to survive, or the message stops being diagnostic.',
        );
    }

    /**
     * The password half keeps its own treatment: there the user name really is
     * an identifier, and both halves are known, so only the secret goes.
     */
    #[Test]
    public function aUserNameInFrontOfAPasswordIsStillReadable(): void
    {
        $redacted = (new CredentialRedactor())->redact(
            'POST https://svc:s3cr3t-token-value@gateway.example.com/v1 returned 401',
            '',
        );

        self::assertStringNotContainsString('s3cr3t-token-value', $redacted);
        self::assertStringContainsString('svc', $redacted);
        self::assertStringContainsString('gateway.example.com', $redacted);
    }
}
