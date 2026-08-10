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

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\ImageGenerationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Registry\DisabledModelRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Regression coverage for the auto-model-switch bug where a general-purpose,
 * multi-capability model (e.g. OpenAI's "chatgpt-image-latest", a Gpt-class model
 * needing a message-array payload) got picked over a single-purpose, dedicated
 * model (e.g. "gpt-image-1", a distinct model class needing a plain string
 * payload) purely because of ModelCatalog declaration order, not because it
 * was actually a better or even compatible match.
 */
final class ProviderResolverTest extends TestCase
{
    #[Test]
    public function autoModelSwitchPrefersTheMostSpecializedModelOnFirstAttempt(): void
    {
        $configuration = new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'model' => 'o3-mini',
            'disabled' => 0,
            'auto_model_switch' => 1,
        ]);

        // Declaration order mirrors the real OpenAI ModelCatalog: the general-purpose
        // "chatgpt-image-latest" model is declared long before the dedicated "gpt-image-1".
        $manifest = new AiProviderManifest(
            identifier: 'openai',
            name: 'OpenAI',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [ConversationCapableInterface::class, ImageGenerationCapableInterface::class],
            serviceName: 'aim.symfony_ai.openai',
            container: $this->createStub(ContainerInterface::class),
            modelCapabilities: [
                'o3-mini' => [ConversationCapableInterface::class],
                'chatgpt-image-latest' => [ConversationCapableInterface::class, ImageGenerationCapableInterface::class],
                'gpt-image-1' => [ImageGenerationCapableInterface::class],
            ],
        );

        $registry = $this->createStub(AiProviderRegistry::class);
        $registry->method('hasProvider')->willReturn(true);
        $registry->method('getProvider')->willReturn($manifest);

        $configurationRepository = $this->createStub(ProviderConfigurationRepository::class);
        $configurationRepository->method('findAll')->willReturn([$configuration]);

        $disabledModelRegistry = $this->createStub(DisabledModelRegistry::class);
        $disabledModelRegistry->method('isDisabled')->willReturn(false);

        // No request history yet — this is the exact "first attempt, no cost data" case that broke.
        $logRepository = $this->createStub(RequestLogRepository::class);
        $logRepository->method('getModelPerformanceProfile')->willReturn([]);

        $resolver = new ProviderResolver($registry, $configurationRepository, $disabledModelRegistry, $logRepository);

        $resolved = $resolver->resolveForCapability(ImageGenerationCapableInterface::class);

        self::assertSame('gpt-image-1', $resolved->configuration->model);
    }

    #[Test]
    public function resolveWithFallbackAcceptsProviderNotationAlongsideConfigurationUids(): void
    {
        $configuration = new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'anthropic',
            'model' => 'claude-3-5-sonnet',
            'disabled' => 0,
        ]);

        $manifest = new AiProviderManifest(
            identifier: 'anthropic',
            name: 'Anthropic',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [ConversationCapableInterface::class],
            serviceName: 'aim.symfony_ai.anthropic',
            container: $this->createStub(ContainerInterface::class),
        );

        $registry = $this->createStub(AiProviderRegistry::class);
        $registry->method('hasProvider')->willReturnCallback(
            static fn(string $identifier): bool => $identifier === 'anthropic',
        );
        $registry->method('getProvider')->willReturn($manifest);

        $configurationRepository = $this->createStub(ProviderConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($configuration);

        $disabledModelRegistry = $this->createStub(DisabledModelRegistry::class);
        $disabledModelRegistry->method('isDisabled')->willReturn(false);

        $logRepository = $this->createStub(RequestLogRepository::class);

        $resolver = new ProviderResolver($registry, $configurationRepository, $disabledModelRegistry, $logRepository);

        // First entry is an unregistered provider notation and must fail over to the
        // second entry, a plain configuration uid, rather than throwing immediately.
        $resolved = $resolver->resolveWithFallback(ConversationCapableInterface::class, ['unknown-provider:some-model', 1]);

        self::assertSame('anthropic', $resolved->configuration->providerIdentifier);
    }
}
