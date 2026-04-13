<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Governance;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Domain\Repository\UsageBudgetRepository;
use B13\Aim\Governance\AccessControlMiddleware;
use B13\Aim\Governance\BudgetService;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AccessControlMiddlewareTest extends TestCase
{
    private function createMiddleware(): AccessControlMiddleware
    {
        return new AccessControlMiddleware(
            new BudgetService($this->createMock(UsageBudgetRepository::class)),
            $this->createMock(RequestLogRepository::class),
            new NullLogger(),
        );
    }

    private function createConfig(array $overrides = []): ProviderConfiguration
    {
        return new ProviderConfiguration(array_merge([
            'uid' => 1,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
            'be_groups' => '',
            'privacy_level' => 'standard',
            'rerouting_allowed' => 1,
        ], $overrides));
    }

    private function createTextRequest(ProviderConfiguration $config): TextGenerationRequest
    {
        return new TextGenerationRequest(
            configuration: $config,
            prompt: 'Hello',
        );
    }

    private function createNextHandler(TextResponse $response): AiMiddlewareHandler
    {
        return new AiMiddlewareHandler(
            static fn() => $response,
        );
    }

    #[Test]
    public function passesThoughWhenNoBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $middleware = $this->createMiddleware();
        $config = $this->createConfig();
        $expected = new TextResponse('ok');

        $result = $middleware->process(
            $this->createTextRequest($config),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler($expected),
        );

        self::assertSame('ok', $result->content);
    }

    #[Test]
    public function adminBypassesAllChecks(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig(['be_groups' => '99']);
        $expected = new TextResponse('admin ok');

        $result = $this->createMiddleware()->process(
            $this->createTextRequest($config),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler($expected),
        );

        self::assertSame('admin ok', $result->content);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function deniesAccessWhenUserNotInAllowedGroups(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->userGroupsUID = [1, 2, 3];
        $user->user = ['uid' => 5];
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig(['be_groups' => '10,20']);

        $result = $this->createMiddleware()->process(
            $this->createTextRequest($config),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler(new TextResponse('should not reach')),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Access denied', $result->errors[0]);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function allowsAccessWhenUserInAllowedGroups(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->userGroupsUID = [1, 10, 3];
        $user->user = ['uid' => 5];
        $user->groupData = ['custom_options' => ''];
        $user->method('getTSConfig')->willReturn([]);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig(['be_groups' => '10,20']);
        $expected = new TextResponse('allowed');

        $result = $this->createMiddleware()->process(
            $this->createTextRequest($config),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler($expected),
        );

        self::assertSame('allowed', $result->content);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function allowsWhenNoGroupRestrictions(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->userGroupsUID = [1];
        $user->user = ['uid' => 5];
        $user->groupData = ['custom_options' => ''];
        $user->method('getTSConfig')->willReturn([]);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig(['be_groups' => '']);
        $expected = new TextResponse('no restrictions');

        $result = $this->createMiddleware()->process(
            $this->createTextRequest($config),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler($expected),
        );

        self::assertSame('no restrictions', $result->content);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function deniesCapabilityWhenPermissionConfiguredButNotGranted(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->userGroupsUID = [1];
        $user->user = ['uid' => 5];
        // Has aim permissions configured, but only text — not vision
        $user->groupData = ['custom_options' => 'aim:capability_text'];
        $user->method('check')->willReturnCallback(
            fn(string $type, string $value) => $type === 'custom_options' && $value === 'aim:capability_text'
        );
        $user->method('getTSConfig')->willReturn([]);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig();
        $visionRequest = new VisionRequest(
            configuration: $config,
            imageData: 'base64data',
            mimeType: 'image/jpeg',
            prompt: 'describe',
        );

        $result = $this->createMiddleware()->process(
            $visionRequest,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler(new TextResponse('should not reach')),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('vision', $result->errors[0]);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function allowsAllCapabilitiesWhenNoAiMPermissionsConfigured(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->userGroupsUID = [1];
        $user->user = ['uid' => 5];
        // No aim: permissions at all - permissive default
        $user->groupData = ['custom_options' => ''];
        $user->method('getTSConfig')->willReturn([]);
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig();
        $visionRequest = new VisionRequest(
            configuration: $config,
            imageData: 'base64data',
            mimeType: 'image/jpeg',
            prompt: 'describe',
        );

        $expected = new TextResponse('vision allowed');
        $result = $this->createMiddleware()->process(
            $visionRequest,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler($expected),
        );

        self::assertSame('vision allowed', $result->content);

        unset($GLOBALS['BE_USER']);
    }
}
