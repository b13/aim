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
use B13\Aim\Capability\ImageGenerationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\ImageGenerationRequest;
use B13\Aim\Request\Message\AbstractMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\ToolDefinition;
use B13\Aim\Request\ToolResult;
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
     * Goes through the full middleware pipeline like every other request
     * (access control, budgets, rate limiting, smart routing, fallback).
     * Logging and cost tracking can't run synchronously here. Content and
     * usage aren't known until the caller drains the stream, so
     * RequestLoggingMiddleware and CostTrackingMiddleware detect the
     * unconsumed stream and defer via register_shutdown_function instead,
     * reading the real numbers off the same StreamChunkIterator once it's
     * been consumed.
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

        $response = $this->dispatch($request, ConversationCapableInterface::class);
        if ($response instanceof ConversationResponse) {
            return $response;
        }

        // Governance middlewares (access control, budgets, rate limits) can
        // short-circuit before the provider is ever called, returning a plain
        // TextResponse. Normalize to this method's declared return type
        // instead of letting a TypeError surface for a denied request.
        return new ConversationResponse($response->content, $response->usage, $response->rawResponse, $response->errors);
    }

    /**
     * Let the model call tools/functions the caller defines.
     *
     * Returns the base TextResponse type (like every proxy method except
     * conversationStream()) rather than ToolCallingResponse, since governance
     * middlewares can short-circuit with a plain TextResponse before the
     * provider is ever called, narrowing the return type here would risk
     * the same TypeError class of bug fixed for conversationStream(). Callers
     * check `instanceof ToolCallingResponse` and `requiresToolExecution()` /
     * `toolCalls` themselves, same as the README's Tier 3 example.
     *
     * For multi-turn tool use: feed the prior turn's tool calls back as an
     * AssistantMessage in $messages, and the executed results as $toolResults.
     *
     * @param list<AbstractMessage> $messages
     * @param list<ToolDefinition> $tools
     * @param list<ToolResult> $toolResults Results from previously invoked tools (for multi-turn)
     */
    public function toolCalling(
        array $messages,
        array $tools,
        array $toolResults = [],
        string $systemPrompt = '',
        int $maxTokens = 1000,
        float $temperature = 0.7,
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): TextResponse {
        $resolvedProvider = $this->resolve(ToolCallingCapableInterface::class, $provider);
        $request = new ToolCallingRequest(
            configuration: $resolvedProvider->configuration,
            messages: $messages,
            tools: $tools,
            systemPrompt: $systemPrompt,
            toolResults: $toolResults,
            maxTokens: $maxTokens,
            temperature: $temperature,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
        );
        return $this->dispatch($request, ToolCallingCapableInterface::class);
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
     * Generate one or more images from a prompt.
     *
     * Pass a reference image (e.g. an existing brand/header image) via
     * $referenceImageData/$referenceMimeType to guide style and composition
     * (image-to-image) instead of generating from the prompt alone.
     *
     * @param array<string, mixed> $options Provider-specific options passed through as-is
     *        (e.g. ['size' => '1536x1024', 'quality' => 'high', 'background' => 'transparent']).
     */
    public function generateImage(
        string $prompt,
        string $referenceImageData = '',
        string $referenceMimeType = '',
        array $options = [],
        int $count = 1,
        string $extensionKey = '',
        string $user = '',
        string $provider = '',
    ): TextResponse {
        $resolvedProvider = $this->resolve(ImageGenerationCapableInterface::class, $provider);
        $request = new ImageGenerationRequest(
            configuration: $resolvedProvider->configuration,
            prompt: $prompt,
            referenceImageData: $referenceImageData,
            referenceMimeType: $referenceMimeType,
            options: $options,
            count: $count,
            user: $user,
            metadata: $this->buildMetadata($extensionKey),
        );
        return $this->dispatch($request, ImageGenerationCapableInterface::class);
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
