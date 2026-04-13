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
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\SmartRoutingMiddleware;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Registry\DisabledModelRegistry;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use B13\Aim\Routing\ComplexitySignalRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Registry;

final class SmartRoutingRestrictionTest extends TestCase
{
    private function createMiddleware(): SmartRoutingMiddleware
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);

        $configRepo = $this->createMock(ProviderConfigurationRepository::class);
        $configRepo->method('findAll')->willReturn([]);
        $registry = new AiProviderRegistry();
        $disabledModels = new DisabledModelRegistry($this->createMock(Registry::class));

        return new SmartRoutingMiddleware(
            $this->createMock(RequestLogRepository::class),
            new ProviderResolver($registry, $configRepo, $disabledModels, $this->createMock(RequestLogRepository::class)),
            new ComplexitySignalRegistry($packageManager),
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
            'rerouting_allowed' => 1,
            'be_groups' => '',
        ], $overrides));
    }

    #[Test]
    public function doesNotRerouteWhenReroutingDisabled(): void
    {
        $config = $this->createConfig(['rerouting_allowed' => 0]);
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'This is a very simple hello world test that should normally be downgraded to a cheaper model.',
        );

        $expected = new TextResponse('protected');
        $handledConfig = null;

        $next = new AiMiddlewareHandler(
            static function ($req, $prov, $conf) use ($expected, &$handledConfig) {
                $handledConfig = $conf;
                return $expected;
            }
        );

        $result = $this->createMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $next,
        );

        // Should pass through to the original config, never rerouted
        self::assertSame('protected', $result->content);
        self::assertSame($config, $handledConfig);
    }

    #[Test]
    public function classifiesAndPassesThroughWhenReroutingAllowed(): void
    {
        $config = $this->createConfig(['rerouting_allowed' => 1]);
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Hi',
        );

        $expected = new TextResponse('ok');
        $result = $this->createMiddleware()->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            new AiMiddlewareHandler(static fn() => $expected),
        );

        self::assertSame('ok', $result->content);
    }
}
