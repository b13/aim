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

use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Request\ResponseFormat;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use Psr\Log\LoggerInterface;

/**
 * Fluent request builder for the AiM AI proxy.
 *
 * Usage:
 *   $response = $ai->request()
 *       ->vision($imageData, 'image/jpeg')
 *       ->prompt('Generate alt text for this image')
 *       ->systemPrompt('You are an accessibility expert.')
 *       ->maxTokens(100)
 *       ->temperature(0.3)
 *       ->from('descriptive_images')
 *       ->send();
 */
final class AiRequestBuilder
{
    private string $type = '';
    private string $prompt = '';
    private string $systemPrompt = '';
    private int $maxTokens = 150;
    private float $temperature = 0.2;
    private string $extensionKey = '';
    private string $user = '';
    private string $providerNotation = '';
    private ?ResponseFormat $responseFormat = null;

    // Vision-specific
    private string $imageData = '';
    private string $mimeType = '';

    // Translation-specific
    private string $translateText = '';
    private string $sourceLanguage = '';
    private string $targetLanguage = '';

    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly AiMiddlewarePipeline $pipeline,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function vision(string $imageData, string $mimeType): self
    {
        $this->type = 'vision';
        $this->imageData = $imageData;
        $this->mimeType = $mimeType;
        return $this;
    }

    public function text(): self
    {
        $this->type = 'text';
        return $this;
    }

    public function translate(string $text, string $sourceLanguage, string $targetLanguage): self
    {
        $this->type = 'translation';
        $this->translateText = $text;
        $this->sourceLanguage = $sourceLanguage;
        $this->targetLanguage = $targetLanguage;
        return $this;
    }

    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    public function systemPrompt(string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    public function maxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }

    public function temperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    public function responseFormat(ResponseFormat $format): self
    {
        $this->responseFormat = $format;
        return $this;
    }

    /**
     * Identify the calling extension for logging and analytics.
     */
    public function from(string $extensionKey): self
    {
        $this->extensionKey = $extensionKey;
        return $this;
    }

    public function user(string $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Target a specific provider (e.g. "openai:gpt-4o", "openai:*").
     * If not set, the default provider is used.
     */
    public function provider(string $notation): self
    {
        $this->providerNotation = $notation;
        return $this;
    }

    /**
     * Send the request through the AiM pipeline.
     */
    public function send(): TextResponse
    {
        $metadata = [];
        if ($this->extensionKey !== '') {
            $metadata['extension'] = $this->extensionKey;
        }

        return match ($this->type) {
            'vision' => $this->sendVision($metadata),
            'text' => $this->sendText($metadata),
            'translation' => $this->sendTranslation($metadata),
            default => throw new \LogicException(
                'No request type set. Call vision(), text(), or translate() before send().',
                1773874300,
            ),
        };
    }

    private function sendVision(array $metadata): TextResponse
    {
        $capabilityClass = VisionCapableInterface::class;
        $resolvedProvider = $this->resolve($capabilityClass);
        $request = new VisionRequest(
            configuration: $resolvedProvider->configuration,
            imageData: $this->imageData,
            mimeType: $this->mimeType,
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            user: $this->user,
            metadata: $metadata,
        );
        return $this->pipeline->dispatchWithFallback(
            $request,
            $this->providerResolver->buildFallbackChain($capabilityClass),
        );
    }

    private function sendText(array $metadata): TextResponse
    {
        $capabilityClass = TextGenerationCapableInterface::class;
        $resolvedProvider = $this->resolve($capabilityClass);
        $request = new TextGenerationRequest(
            configuration: $resolvedProvider->configuration,
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            responseFormat: $this->responseFormat,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            user: $this->user,
            metadata: $metadata,
        );
        return $this->pipeline->dispatchWithFallback(
            $request,
            $this->providerResolver->buildFallbackChain($capabilityClass),
        );
    }

    private function sendTranslation(array $metadata): TextResponse
    {
        $capabilityClass = TranslationCapableInterface::class;
        $resolvedProvider = $this->resolve($capabilityClass);
        $request = new TranslationRequest(
            configuration: $resolvedProvider->configuration,
            text: $this->translateText,
            sourceLanguage: $this->sourceLanguage,
            targetLanguage: $this->targetLanguage,
            systemPrompt: $this->systemPrompt,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            user: $this->user,
            metadata: $metadata,
        );
        return $this->pipeline->dispatchWithFallback(
            $request,
            $this->providerResolver->buildFallbackChain($capabilityClass),
        );
    }

    private function resolve(string $capabilityClass): ResolvedProvider
    {
        if ($this->providerNotation !== '') {
            try {
                return $this->providerResolver->resolveByString($this->providerNotation, $capabilityClass);
            } catch (\Throwable $e) {
                $this->logger?->warning(
                    sprintf('Requested provider "%s" unavailable, falling back to default. Reason: %s', $this->providerNotation, $e->getMessage())
                );
            }
        }
        return $this->providerResolver->resolveForCapability($capabilityClass);
    }
}
