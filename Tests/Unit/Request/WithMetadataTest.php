<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Request;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\ToolDefinition;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the withMetadata() contract across every concrete request DTO:
 * immutable evolution, deep merge with later-key-wins, other fields untouched.
 */
final class WithMetadataTest extends TestCase
{
    public static function requestProvider(): \Generator
    {
        $configuration = new ProviderConfiguration(['uid' => 1, 'ai_provider' => 'test']);

        yield 'TextGenerationRequest' => [
            new TextGenerationRequest(
                configuration: $configuration,
                prompt: 'Hello',
                metadata: ['existing' => 'value', 'shared' => 'old'],
            ),
        ];
        yield 'VisionRequest' => [
            new VisionRequest(
                configuration: $configuration,
                imageData: 'b64',
                mimeType: 'image/jpeg',
                prompt: 'Describe',
                metadata: ['existing' => 'value', 'shared' => 'old'],
            ),
        ];
        yield 'ConversationRequest' => [
            new ConversationRequest(
                configuration: $configuration,
                messages: [new UserMessage('hi')],
                metadata: ['existing' => 'value', 'shared' => 'old'],
            ),
        ];
        yield 'TranslationRequest' => [
            new TranslationRequest(
                configuration: $configuration,
                text: 'Hello',
                sourceLanguage: 'en',
                targetLanguage: 'de',
                metadata: ['existing' => 'value', 'shared' => 'old'],
            ),
        ];
        yield 'EmbeddingRequest' => [
            new EmbeddingRequest(
                configuration: $configuration,
                input: ['text'],
                metadata: ['existing' => 'value', 'shared' => 'old'],
            ),
        ];
        yield 'ToolCallingRequest' => [
            new ToolCallingRequest(
                configuration: $configuration,
                messages: [new UserMessage('hi')],
                tools: [new ToolDefinition(name: 't', description: 'd', parameters: [])],
                metadata: ['existing' => 'value', 'shared' => 'old'],
            ),
        ];
    }

    #[Test]
    #[DataProvider('requestProvider')]
    public function returnsNewInstanceLeavingOriginalUntouched(AiRequestInterface $request): void
    {
        $enriched = $request->withMetadata(['added' => 1]);

        self::assertNotSame($request, $enriched);
        self::assertSame(['existing' => 'value', 'shared' => 'old'], $this->metadataOf($request));
    }

    #[Test]
    #[DataProvider('requestProvider')]
    public function mergesAdditionalKeysIntoExistingMetadata(AiRequestInterface $request): void
    {
        $enriched = $request->withMetadata(['added' => 1, 'shared' => 'new']);

        self::assertSame(
            ['existing' => 'value', 'shared' => 'new', 'added' => 1],
            $this->metadataOf($enriched),
        );
    }

    #[Test]
    #[DataProvider('requestProvider')]
    public function preservesAllOtherFields(AiRequestInterface $request): void
    {
        $enriched = $request->withMetadata(['added' => 1]);

        $original = get_object_vars($request);
        $after = get_object_vars($enriched);
        unset($original['metadata'], $after['metadata']);
        self::assertSame($original, $after);
    }

    #[Test]
    #[DataProvider('requestProvider')]
    public function chainedCallsAccumulateAcrossLaterWins(AiRequestInterface $request): void
    {
        $enriched = $request
            ->withMetadata(['first' => 1, 'shared' => 'mid'])
            ->withMetadata(['second' => 2, 'shared' => 'last']);

        self::assertSame(
            ['existing' => 'value', 'shared' => 'last', 'first' => 1, 'second' => 2],
            $this->metadataOf($enriched),
        );
    }

    private function metadataOf(AiRequestInterface $request): array
    {
        return get_object_vars($request)['metadata'];
    }
}
