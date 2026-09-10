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

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Provider\SymfonyAi\SymfonyAiPlatformAdapter;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Tests\Unit\Provider\Fixtures\ThrowingPlatformFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The bridge factory is handed the decrypted credential, so a throw from
 * building the platform has to be redacted like any other provider failure.
 * It used to happen before the try block, and that message is persisted to
 * tx_aim_request_log.error_message and returned as JSON by an endpoint a
 * non-admin editor can reach.
 */
final class PlatformConstructionErrorTest extends TestCase
{
    #[Test]
    public function aCredentialQuotedByTheFactoryDoesNotReachTheCaller(): void
    {
        $adapter = new SymfonyAiPlatformAdapter(ThrowingPlatformFactory::class);
        $configuration = new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'test',
            'model' => 'a-model',
            'api_key' => 'sk-proj-A1b2C3d4E5f6',
            'endpoint' => 'https://gateway.example.com/v1',
        ]);

        $response = $adapter->processTextGenerationRequest(
            new TextGenerationRequest($configuration, 'Say hello.')
        );

        self::assertFalse($response->isSuccessful());
        $error = $response->errors[0] ?? '';
        self::assertStringNotContainsString('sk-proj-A1b2C3d4E5f6', $error);
        // Still diagnostic: the host survives, only the secret is taken out.
        self::assertStringContainsString('gateway.example.com', $error);
    }
}
