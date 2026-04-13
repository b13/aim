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
use B13\Aim\Governance\AccessControlMiddleware;
use B13\Aim\Governance\BudgetService;
use B13\Aim\Domain\Repository\UsageBudgetRepository;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the AccessControlMiddleware.
 *
 * Tests the full governance chain with real database operations
 * for budget tracking, rate limiting, and provider access.
 */
final class AccessControlTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private function createMiddleware(): AccessControlMiddleware
    {
        return new AccessControlMiddleware(
            new BudgetService($this->get(UsageBudgetRepository::class)),
            $this->get(RequestLogRepository::class),
            new NullLogger(),
        );
    }

    private function createConfig(array $overrides = []): ProviderConfiguration
    {
        return new ProviderConfiguration(array_merge([
            'uid' => 1,
            'ai_provider' => 'test',
            'title' => 'Test Provider',
            'api_key' => 'test-key',
            'model' => 'test-model',
            'be_groups' => '',
            'privacy_level' => 'standard',
            'rerouting_allowed' => 1,
        ], $overrides));
    }

    private function passThrough(): AiMiddlewareHandler
    {
        return new AiMiddlewareHandler(
            static fn() => new TextResponse('ok'),
        );
    }

    private function mockUser(array $options = []): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($options['isAdmin'] ?? false);
        $user->userGroupsUID = $options['groupIds'] ?? [1];
        $user->user = ['uid' => $options['uid'] ?? 5];
        $user->groupData = ['custom_options' => $options['customOptions'] ?? ''];
        $user->method('check')->willReturnCallback(
            function (string $type, string $value) use ($options) {
                if ($type !== 'custom_options') {
                    return false;
                }
                $allowed = $options['allowedPermissions'] ?? [];
                return in_array($value, $allowed, true);
            }
        );
        $user->method('getTSConfig')->willReturn($options['tsConfig'] ?? []);
        $GLOBALS['BE_USER'] = $user;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function providerWithGroupRestrictionBlocksUnauthorizedUser(): void
    {
        $this->mockUser(['groupIds' => [1, 2]]);
        $config = $this->createConfig(['be_groups' => '10,20']);

        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Access denied', $result->errors[0]);
    }

    #[Test]
    public function providerWithGroupRestrictionAllowsAuthorizedUser(): void
    {
        $this->mockUser(['groupIds' => [1, 10]]);
        $config = $this->createConfig(['be_groups' => '10,20']);

        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertTrue($result->isSuccessful());
    }

    #[Test]
    public function blocksVisionWhenOnlyTextPermissionGranted(): void
    {
        $this->mockUser([
            'customOptions' => 'aim:capability_text',
            'allowedPermissions' => ['aim:capability_text'],
        ]);
        $config = $this->createConfig();

        $result = $this->createMiddleware()->process(
            new VisionRequest(configuration: $config, imageData: 'data', mimeType: 'image/jpeg', prompt: 'describe'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('vision', $result->errors[0]);
    }

    #[Test]
    public function allowsTextWhenTextPermissionGranted(): void
    {
        $this->mockUser([
            'customOptions' => 'aim:capability_text',
            'allowedPermissions' => ['aim:capability_text'],
        ]);
        $config = $this->createConfig();

        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertTrue($result->isSuccessful());
    }

    #[Test]
    public function allowsEverythingWhenNoAiMPermissionsConfigured(): void
    {
        $this->mockUser(['customOptions' => '']);
        $config = $this->createConfig();

        $result = $this->createMiddleware()->process(
            new VisionRequest(configuration: $config, imageData: 'data', mimeType: 'image/jpeg', prompt: 'describe'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertTrue($result->isSuccessful());
    }

    #[Test]
    public function blockWhenBudgetExceeded(): void
    {
        // Pre-fill budget table
        $budgetRepo = $this->get(UsageBudgetRepository::class);
        $budgetRepo->recordUsage(5, 'monthly', 0, 0);
        // Simulate exceeding by updating directly
        $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_usage_budget')
            ->update('tx_aim_usage_budget', ['cost_used' => 100.0], ['user_id' => 5]);

        $this->mockUser([
            'uid' => 5,
            'tsConfig' => [
                'aim.' => [
                    'budget.' => [
                        'period' => 'monthly',
                        'maxCost' => '50.00',
                    ],
                ],
            ],
        ]);

        $config = $this->createConfig();
        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Cost budget exceeded', $result->errors[0]);
    }

    #[Test]
    public function allowsWhenWithinBudget(): void
    {
        $budgetRepo = $this->get(UsageBudgetRepository::class);
        $budgetRepo->recordUsage(5, 'monthly', 100, 5.0);

        $this->mockUser([
            'uid' => 5,
            'tsConfig' => [
                'aim.' => [
                    'budget.' => [
                        'period' => 'monthly',
                        'maxCost' => '50.00',
                    ],
                ],
            ],
        ]);

        $config = $this->createConfig();
        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertTrue($result->isSuccessful());
    }

    #[Test]
    public function blocksWhenRateLimitExceeded(): void
    {
        // Insert 10 recent log entries for user 5
        $logRepo = $this->get(RequestLogRepository::class);
        for ($i = 0; $i < 10; $i++) {
            $logRepo->log([
                'user_id' => 5,
                'crdate' => time(),
                'request_type' => 'TextGenerationRequest',
                'provider_identifier' => 'test',
                'success' => 1,
            ]);
        }

        $this->mockUser([
            'uid' => 5,
            'tsConfig' => [
                'aim.' => [
                    'rateLimit.' => [
                        'requestsPerMinute' => '5',
                    ],
                ],
            ],
        ]);

        $config = $this->createConfig();
        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Rate limit exceeded', $result->errors[0]);
    }

    // --- Admin Bypass ---

    #[Test]
    public function adminBypassesGroupRestriction(): void
    {
        $this->mockUser(['isAdmin' => true, 'groupIds' => []]);
        $config = $this->createConfig(['be_groups' => '99']);

        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertTrue($result->isSuccessful());
    }

    #[Test]
    public function adminBypassesBudgetLimits(): void
    {
        $this->mockUser([
            'isAdmin' => true,
            'uid' => 1,
            'tsConfig' => [
                'aim.' => [
                    'budget.' => ['maxCost' => '0.01'],
                ],
            ],
        ]);

        $config = $this->createConfig();
        $result = $this->createMiddleware()->process(
            new TextGenerationRequest(configuration: $config, prompt: 'test'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->passThrough(),
        );

        self::assertTrue($result->isSuccessful());
    }
}
