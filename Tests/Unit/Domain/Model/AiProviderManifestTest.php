<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Domain\Model;

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\ImageGenerationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Regression coverage for the "dynamic-catalog bridge (e.g. Ollama) gets wrongly
 * credited with image generation" bug: a provider with no static ModelCatalog
 * (empty modelCapabilities) has no per-model data to confirm image generation
 * with, so it must never be blanket-granted the way other capabilities are.
 */
final class AiProviderManifestTest extends TestCase
{
    private function manifest(array $capabilities, array $modelCapabilities = []): AiProviderManifest
    {
        return new AiProviderManifest(
            identifier: 'test',
            name: 'Test',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: $capabilities,
            serviceName: 'test.service',
            container: $this->createStub(ContainerInterface::class),
            modelCapabilities: $modelCapabilities,
        );
    }

    #[Test]
    public function dynamicCatalogProviderInheritsOrdinaryCapabilitiesForAnyModel(): void
    {
        $manifest = $this->manifest([ConversationCapableInterface::class, ImageGenerationCapableInterface::class]);

        self::assertTrue($manifest->hasModelCapability('llama3.2:latest', ConversationCapableInterface::class));
    }

    #[Test]
    public function dynamicCatalogProviderNeverInheritsImageGeneration(): void
    {
        $manifest = $this->manifest([ConversationCapableInterface::class, ImageGenerationCapableInterface::class]);

        self::assertFalse($manifest->hasModelCapability('llama3.2:latest', ImageGenerationCapableInterface::class));
    }

    #[Test]
    public function staticCatalogModelExplicitlyGrantedImageGenerationStillHasIt(): void
    {
        $manifest = $this->manifest(
            [ConversationCapableInterface::class, ImageGenerationCapableInterface::class],
            ['gpt-image-1' => [ImageGenerationCapableInterface::class]],
        );

        self::assertTrue($manifest->hasModelCapability('gpt-image-1', ImageGenerationCapableInterface::class));
    }

    #[Test]
    public function staticCatalogModelListedWithoutCapabilitiesHasNone(): void
    {
        $manifest = $this->manifest(
            [ConversationCapableInterface::class, TextGenerationCapableInterface::class],
            [
                'chat-model' => [ConversationCapableInterface::class, TextGenerationCapableInterface::class],
                'speech-to-text-model' => [],
            ],
        );

        self::assertFalse($manifest->hasModelCapability('speech-to-text-model', ConversationCapableInterface::class));
    }

    /**
     * A chat model listing image output at the top capability count made image
     * generation look like a capability every unlisted model shares.
     */
    #[Test]
    public function unlistedModelOfStaticCatalogNeverInheritsImageGeneration(): void
    {
        $manifest = $this->manifestWithImageOutputOnChatModel();

        self::assertFalse($manifest->hasModelCapability('unlisted-model', ImageGenerationCapableInterface::class));
    }

    #[Test]
    public function unlistedModelOfStaticCatalogStillInheritsConversation(): void
    {
        $manifest = $this->manifestWithImageOutputOnChatModel();

        self::assertTrue($manifest->hasModelCapability('unlisted-model', ConversationCapableInterface::class));
    }

    private function manifestWithImageOutputOnChatModel(): AiProviderManifest
    {
        $chat = [
            VisionCapableInterface::class,
            ConversationCapableInterface::class,
            TextGenerationCapableInterface::class,
            ToolCallingCapableInterface::class,
            ImageGenerationCapableInterface::class,
            TranslationCapableInterface::class,
        ];

        return $this->manifest($chat, [
            'chat-model' => $chat,
            'image-model' => [ImageGenerationCapableInterface::class],
        ]);
    }
}
