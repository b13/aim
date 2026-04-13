<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim;

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\Message\AbstractMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\TextResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * The central AI proxy for TYPO3.
 *
 * AiM is the single entry point for all AI operations. Consuming extensions
 * describe WHAT they need and AiM decides which provider and model to use,
 * routes through the middleware pipeline (logging, cost tracking, capability
 * validation, fallback), and returns the result.
 *
 * Extensions don't need to interact with providers, configurations, or the
 * pipeline directly. This makes transparent rerouting, smart model selection,
 * and centralized analytics possible.
 *
 * Usage:
 *   // Vision (e.g. alt text generation)
 *   $response = $ai->vision($imageData, 'image/jpeg', 'Describe this image');
 *
 *   // Text generation
 *   $response = $ai->text('Summarize this article: ...');
 *
 *   // Translation
 *   $response = $ai->translate('Hello world', 'English', 'German');
 *
 *   // Advanced: fluent builder
 *   $response = $ai->request()
 *       ->vision($imageData, 'image/jpeg')
 *       ->prompt('Generate alt text')
 *       ->systemPrompt('You are an accessibility expert.')
 *       ->maxTokens(100)
 *       ->temperature(0.3)
 *       ->from('descriptive_images')
 *       ->send();
 */
#[Autoconfigure(public: true)]
final class Ai
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly AiMiddlewarePipeline $pipeline,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate text from an image (vision capability).
     */
    public function vision(
        string $imageData,
        string $mimeType,
        string $prompt,
        string $systemPrompt = '',
        int $maxTokens = 150,
        float $temperature = 0.2,
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): TextResponse {
        $resolvedProvider = $this->resolve(VisionCapableInterface::class, $provider);
        $request = new VisionRequest(
            configuration: $resolvedProvider->configuration,
            imageData: $imageData,
            mimeType: $mimeType,
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            temperature: $temperature,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
        );
        return $this->dispatch($request, VisionCapableInterface::class);
    }

    /**
     * Generate text from a prompt (text generation capability).
     */
    public function text(
        string $prompt,
        string $systemPrompt = '',
        int $maxTokens = 500,
        float $temperature = 0.7,
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): TextResponse {
        $resolvedProvider = $this->resolve(TextGenerationCapableInterface::class, $provider);
        $request = new TextGenerationRequest(
            configuration: $resolvedProvider->configuration,
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            temperature: $temperature,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
        );
        return $this->dispatch($request, TextGenerationCapableInterface::class);
    }

    /**
     * Translate text between languages.
     */
    public function translate(
        string $text,
        string $sourceLanguage,
        string $targetLanguage,
        string $systemPrompt = '',
        int $maxTokens = 500,
        float $temperature = 0.3,
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): TextResponse {
        $resolvedProvider = $this->resolve(TranslationCapableInterface::class, $provider);
        $request = new TranslationRequest(
            configuration: $resolvedProvider->configuration,
            text: $text,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            temperature: $temperature,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
        );
        return $this->dispatch($request, TranslationCapableInterface::class);
    }

    /**
     * Have a conversation with an AI model.
     *
     * @param list<AbstractMessage> $messages
     */
    public function conversation(
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 1000,
        float $temperature = 0.7,
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): TextResponse {
        $resolvedProvider = $this->resolve(ConversationCapableInterface::class, $provider);
        $request = new ConversationRequest(
            configuration: $resolvedProvider->configuration,
            messages: $messages,
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            temperature: $temperature,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
        );
        return $this->dispatch($request, ConversationCapableInterface::class);
    }

    /**
     * Stream a conversation response token-by-token.
     *
     * Returns a ConversationResponse with a streamIterator that yields
     * string chunks as they arrive from the provider. The caller iterates
     * the stream and sends chunks to the client (e.g. via SSE).
     *
     * Note: streaming bypasses the middleware pipeline for the response path.
     * The request is still resolved and validated, but logging and cost
     * tracking happen after the stream completes via the StreamChunkIterator's
     * onComplete callback.
     *
     * @param list<AbstractMessage> $messages
     */
    public function conversationStream(
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 1000,
        float $temperature = 0.7,
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): ConversationResponse {
        $resolvedProvider = $this->resolve(ConversationCapableInterface::class, $provider);
        $request = new ConversationRequest(
            configuration: $resolvedProvider->configuration,
            messages: $messages,
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            temperature: $temperature,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
            stream: true,
        );

        // For streaming, call the provider directly to get the stream iterator.
        // The full middleware pipeline is synchronous — it can't handle streaming.
        return $resolvedProvider->getCapability(ConversationCapableInterface::class)->processConversationRequest($request);
    }

    /**
     * Get an embedding vector for text.
     */
    public function embed(
        string|array $input,
        int $dimensions = 0,
        string $model = '',
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): TextResponse {
        $resolvedProvider = $this->resolve(EmbeddingCapableInterface::class, $provider);
        $request = new EmbeddingRequest(
            configuration: $resolvedProvider->configuration,
            input: is_array($input) ? $input : [$input],
            model: $model,
            dimensions: $dimensions,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
        );
        return $this->dispatch($request, EmbeddingCapableInterface::class);
    }

    /**
     * Start a fluent request builder for advanced use cases.
     */
    public function request(): AiRequestBuilder
    {
        return new AiRequestBuilder($this->providerResolver, $this->pipeline, $this->logger);
    }

    /**
     * Resolve a provider — by notation if given, otherwise the default for the capability.
     *
     * If a specific provider is requested but unavailable (not installed, no config),
     * falls back to the default provider for the capability instead of failing.
     *
     * @param class-string $capabilityFqcn
     * @param string $provider Provider notation ("openai:gpt-4o", "openai:*", or "" for default)
     */
    private function resolve(string $capabilityFqcn, string $provider): ResolvedProvider
    {
        if ($provider !== '') {
            try {
                return $this->providerResolver->resolveByString($provider, $capabilityFqcn);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('Requested provider "%s" unavailable, falling back to default. Reason: %s', $provider, $e->getMessage())
                );
            }
        }
        return $this->providerResolver->resolveForCapability($capabilityFqcn);
    }

    private function dispatch(AiRequestInterface $request, string $capabilityFqcn): TextResponse
    {
        return $this->pipeline->dispatchWithFallback(
            $request,
            $this->providerResolver->buildFallbackChain($capabilityFqcn),
        );
    }

    private function buildMetadata(string $extensionKey): array
    {
        $metadata = [];
        if ($extensionKey !== '') {
            $metadata['extension'] = $extensionKey;
        }
        return $metadata;
    }
}
