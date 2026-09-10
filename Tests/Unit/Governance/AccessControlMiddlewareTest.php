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
use B13\Aim\Governance\RateLimitCounter;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class AccessControlMiddlewareTest extends TestCase
{
    private function createMiddleware(): AccessControlMiddleware
    {
        return new AccessControlMiddleware(
            new BudgetService($this->createMock(UsageBudgetRepository::class)),
            new RateLimitCounter($this->createMock(CacheManager::class), new NullLogger()),
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

    /**
     * A working cache, so the limiter actually counts.
     */
    private function countingCacheManager(): CacheManager
    {
        /** @var array<string, int> $store */
        $store = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willReturn(false);
        // A closure, not an arrow function: the latter captures $store by
        // value, so every read would see the empty array it started with.
        $cache->method('get')->willReturnCallback(function (string $id) use (&$store) {
            return $store[$id] ?? false;
        });
        $cache->method('set')->willReturnCallback(function (string $id, $data) use (&$store): void {
            $store[$id] = $data;
        });
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        return $cacheManager;
    }

    private function middlewareWithWorkingCounter(): AccessControlMiddleware
    {
        return new AccessControlMiddleware(
            new BudgetService($this->createMock(UsageBudgetRepository::class)),
            new RateLimitCounter($this->countingCacheManager(), new NullLogger()),
            new NullLogger(),
        );
    }

    private function dispatch(AccessControlMiddleware $middleware, ProviderConfiguration $config): TextResponse
    {
        return $middleware->process(
            $this->createTextRequest($config),
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->createNextHandler(new TextResponse('ok')),
        );
    }

    /**
     * The exemption is for command-line runs, and it used to be a comparison
     * against the username "_cli_". An admin can create a non-admin account
     * with that name and log in with it through the backend, which would make
     * it permanently exempt from the limit.
     */
    #[Test]
    public function aBackendUserNamedLikeTheCliAccountIsStillRateLimited(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getTSConfig')->willReturn(['aim.' => ['rateLimit.' => ['requestsPerMinute' => '1']]]);
        $user->user = ['uid' => 7, 'username' => '_cli_'];
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig();
        $middleware = $this->middlewareWithWorkingCounter();

        self::assertSame('ok', $this->dispatch($middleware, $config)->content);
        $second = $this->dispatch($middleware, $config);

        unset($GLOBALS['BE_USER']);
        self::assertNotSame('ok', $second->content, 'The second request should have exceeded the limit of 1.');
        self::assertNotSame([], $second->errors);
    }

    #[Test]
    public function aCommandLineRunIsExemptFromTheLimit(): void
    {
        $user = $this->createMock(CommandLineUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getTSConfig')->willReturn(['aim.' => ['rateLimit.' => ['requestsPerMinute' => '1']]]);
        $user->user = ['uid' => 7, 'username' => '_cli_'];
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig();
        $middleware = $this->middlewareWithWorkingCounter();

        $first = $this->dispatch($middleware, $config);
        $second = $this->dispatch($middleware, $config);

        unset($GLOBALS['BE_USER']);
        self::assertSame('ok', $first->content);
        self::assertSame('ok', $second->content, 'A scheduler or command run must not be capped.');
    }

    /**
     * `= 0` is the documented off switch, so a typo must not look like one.
     */
    #[Test]
    public function aRateLimitTSconfigValueThatIsNotANumberFallsBackToTheDefault(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getTSConfig')->willReturn(['aim.' => ['rateLimit.' => ['requestsPerMinute' => 'sixty']]]);
        $user->user = ['uid' => 7, 'username' => 'editor'];
        $GLOBALS['BE_USER'] = $user;

        $config = $this->createConfig();
        $middleware = $this->middlewareWithWorkingCounter();

        // The default is 60, so 61 dispatches must end in a refusal. If the
        // typo had turned the limiter off, all of them would pass.
        $refused = null;
        for ($i = 0; $i < 61; $i++) {
            $response = $this->dispatch($middleware, $config);
            if ($response->content !== 'ok') {
                $refused = $response;
                break;
            }
        }

        unset($GLOBALS['BE_USER']);
        self::assertNotNull($refused, 'An unparseable value turned the rate limit off.');
        self::assertNotSame([], $refused->errors);
    }
}
