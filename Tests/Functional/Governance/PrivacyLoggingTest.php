<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Governance;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Governance\PrivacyLevel;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\RequestLoggingMiddleware;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests that privacy levels correctly control what gets logged.
 */
final class PrivacyLoggingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private function createLoggingMiddleware(): RequestLoggingMiddleware
    {
        return new RequestLoggingMiddleware(
            $this->get(RequestLogRepository::class),
            new NullLogger(),
        );
    }

    private function createConfig(string $privacyLevel): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'test',
            'title' => 'Test',
            'api_key' => 'key',
            'model' => 'model',
            'privacy_level' => $privacyLevel,
            'rerouting_allowed' => 1,
        ]);
    }

    private function respondWith(string $content): AiMiddlewareHandler
    {
        return new AiMiddlewareHandler(
            static fn() => new TextResponse(
                $content,
                new AiUsageStatistics(promptTokens: 10, completionTokens: 20),
            ),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function standardPrivacyLogsFullContent(): void
    {
        $config = $this->createConfig('standard');
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'What is the salary of John?',
            systemPrompt: 'You are HR assistant.',
        );

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('John earns $100k.'),
        );

        $rows = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->select(['*'], 'tx_aim_request_log')
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('What is the salary of John?', $rows[0]['request_prompt']);
        self::assertSame('You are HR assistant.', $rows[0]['request_system_prompt']);
        self::assertSame('John earns $100k.', $rows[0]['response_content']);
    }

    #[Test]
    public function reducedPrivacyLogsMetadataButRedactsContent(): void
    {
        $config = $this->createConfig('reduced');
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Confidential question',
            systemPrompt: 'Secret system prompt',
        );

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('Confidential answer'),
        );

        $rows = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->select(['*'], 'tx_aim_request_log')
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        // Metadata should be present
        self::assertSame('TextGenerationRequest', $rows[0]['request_type']);
        self::assertSame('test', $rows[0]['provider_identifier']);
        self::assertSame(10, (int)$rows[0]['prompt_tokens']);
        self::assertSame(20, (int)$rows[0]['completion_tokens']);
        // Content should be redacted
        self::assertSame('', $rows[0]['request_prompt']);
        self::assertSame('', $rows[0]['request_system_prompt']);
        self::assertSame('', $rows[0]['response_content']);
    }

    #[Test]
    public function nonePrivacySkipsLoggingEntirely(): void
    {
        $config = $this->createConfig('none');
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Top secret',
        );

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('Classified response'),
        );

        $count = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->count('*', 'tx_aim_request_log', []);

        self::assertSame(0, $count);
    }

    #[Test]
    public function userTsconfigCanEscalatePrivacy(): void
    {
        // Config says standard, but user TSconfig says reduced — reduced wins
        $user = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $user->method('getTSConfig')->willReturn([
            'aim.' => ['privacyLevel' => 'reduced'],
        ]);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig('standard');
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Should be redacted',
        );

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('Also redacted'),
        );

        $rows = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->select(['*'], 'tx_aim_request_log')
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]['request_prompt']);
        self::assertSame('', $rows[0]['response_content']);
    }

    #[Test]
    public function requestOverrideCanEscalatePrivacyToNone(): void
    {
        // Config says standard, request says none → none wins, nothing logged.
        $config = $this->createConfig('standard');
        $request = (new TextGenerationRequest(
            configuration: $config,
            prompt: 'Health check',
        ))->withPrivacyLevel(PrivacyLevel::None);

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('Pong'),
        );

        $count = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->count('*', 'tx_aim_request_log', []);

        self::assertSame(0, $count);
    }

    #[Test]
    public function requestOverrideCannotRelaxConfigPrivacy(): void
    {
        // Config says none, request says standard → none still wins (stricter),
        // nothing logged. The request can only escalate, never relax.
        $config = $this->createConfig('none');
        $request = (new TextGenerationRequest(
            configuration: $config,
            prompt: 'Top secret',
        ))->withPrivacyLevel(PrivacyLevel::Standard);

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('Classified'),
        );

        $count = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->count('*', 'tx_aim_request_log', []);

        self::assertSame(0, $count);
    }

    #[Test]
    public function requestOverrideCannotRelaxUserTsconfig(): void
    {
        // User TSconfig says reduced, request says standard → reduced still wins.
        $user = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $user->method('getTSConfig')->willReturn([
            'aim.' => ['privacyLevel' => 'reduced'],
        ]);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig('standard');
        $request = (new TextGenerationRequest(
            configuration: $config,
            prompt: 'Should still be redacted',
        ))->withPrivacyLevel(PrivacyLevel::Standard);

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('Also redacted'),
        );

        $rows = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->select(['*'], 'tx_aim_request_log')
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]['request_prompt']);
        self::assertSame('', $rows[0]['response_content']);
    }

    #[Test]
    public function userTsconfigCannotDowngradePrivacy(): void
    {
        // Config says none, user TSconfig says standard — none wins (stricter)
        $user = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $user->method('getTSConfig')->willReturn([
            'aim.' => ['privacyLevel' => 'standard'],
        ]);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig('none');
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Should not be logged at all',
        );

        $this->createLoggingMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->respondWith('Nothing here'),
        );

        $count = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->count('*', 'tx_aim_request_log', []);

        self::assertSame(0, $count);
    }
}