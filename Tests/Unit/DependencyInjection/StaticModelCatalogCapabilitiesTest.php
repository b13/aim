<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection;

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Capability\ImageGenerationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\DependencyInjection\SymfonyAiCompilerPass;
use B13\Aim\Domain\Model\AiProviderManifest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Every bridge was granted every AiM capability on provider level, even when
 * its static catalog lists no model offering one of them. A bridge without
 * any image model showed image generation, and catalog models mapping to no
 * AiM capability (speech-to-text, text-to-speech) were left out of the
 * per-model list and then treated as unlisted. Reported as #41.
 */
final class StaticModelCatalogCapabilitiesTest extends UnitTestCase
{
    private const FIXTURES = 'B13\\Aim\\Tests\\Unit\\DependencyInjection\\Fixtures\\';

    #[Test]
    public function providerCapabilitiesAreTheUnionOfTheCatalogModels(): void
    {
        $bridge = $this->buildBridge('acme/ai-platform', self::FIXTURES . 'StaticCatalog');

        self::assertIsArray($bridge);
        self::assertSame('acme', $bridge['identifier']);
        self::assertEqualsCanonicalizing(
            [
                VisionCapableInterface::class,
                ConversationCapableInterface::class,
                TextGenerationCapableInterface::class,
                TranslationCapableInterface::class,
                ToolCallingCapableInterface::class,
                EmbeddingCapableInterface::class,
            ],
            $bridge['capabilities'],
        );
    }

    #[Test]
    public function catalogModelsMappingToNoCapabilityAreListedWithoutAny(): void
    {
        $bridge = $this->buildBridge('acme/ai-platform', self::FIXTURES . 'StaticCatalog');

        self::assertIsArray($bridge);
        self::assertSame([], $bridge['modelCapabilities']['speech-to-text-model'] ?? null);
        self::assertSame([], $bridge['modelCapabilities']['text-to-speech-model'] ?? null);
    }

    #[Test]
    public function speechToTextModelGetsNeitherImageGenerationNorConversation(): void
    {
        $bridge = $this->buildBridge('acme/ai-platform', self::FIXTURES . 'StaticCatalog');
        self::assertIsArray($bridge);

        $manifest = $this->manifestFor($bridge);

        self::assertFalse($manifest->hasModelCapability('speech-to-text-model', ImageGenerationCapableInterface::class));
        self::assertFalse($manifest->hasModelCapability('speech-to-text-model', ConversationCapableInterface::class));
    }

    /**
     * A catalog whose models offer nothing AiM can use leaves the bridge
     * without capabilities, rather than falling back to all of them.
     */
    #[Test]
    public function aCatalogOfOnlyUnmappedModelsGrantsNothing(): void
    {
        $bridge = $this->buildBridge('acme/ai-voice-platform', self::FIXTURES . 'UnmappedCatalog');

        self::assertIsArray($bridge);
        self::assertSame([], $bridge['capabilities']);

        $manifest = $this->manifestFor($bridge);
        $allCapabilities = (new \ReflectionClassConstant(SymfonyAiCompilerPass::class, 'ALL_CAPABILITIES'))->getValue();
        foreach (['speech-to-text-model', 'text-to-speech-model', 'unlisted-model'] as $model) {
            foreach ($allCapabilities as $capability) {
                self::assertFalse($manifest->hasModelCapability($model, $capability), $model . ' got ' . $capability);
            }
        }
    }

    /**
     * Without models to read from, the pass cannot narrow anything down, so the
     * bridge keeps the full set.
     */
    #[Test]
    public function aBridgeWithoutCatalogModelsKeepsAllCapabilities(): void
    {
        $bridge = $this->buildBridge('symfony/ai-example-platform', self::FIXTURES . 'EmptyCatalog');

        self::assertIsArray($bridge);
        self::assertSame([], $bridge['modelCapabilities']);
        self::assertSame(
            (new \ReflectionClassConstant(SymfonyAiCompilerPass::class, 'ALL_CAPABILITIES'))->getValue(),
            $bridge['capabilities'],
        );
        self::assertContains(TextGenerationCapableInterface::class, $bridge['capabilities']);
    }

    /**
     * @param array<string, mixed> $bridge
     */
    private function manifestFor(array $bridge): AiProviderManifest
    {
        return new AiProviderManifest(
            identifier: $bridge['identifier'],
            name: $bridge['name'],
            description: $bridge['description'],
            iconIdentifier: 'tx-aim',
            supportedModels: $bridge['models'],
            capabilities: $bridge['capabilities'],
            serviceName: 'test.service',
            container: $this->createStub(ContainerInterface::class),
            modelCapabilities: $bridge['modelCapabilities'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildBridge(string $packageName, string $namespace): ?array
    {
        $method = new \ReflectionMethod(SymfonyAiCompilerPass::class, 'buildBridgeDefinition');

        return $method->invoke(new SymfonyAiCompilerPass(), [
            'name' => $packageName,
            'namespace' => $namespace,
        ]);
    }
}
