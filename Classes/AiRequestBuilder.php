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

use B13\Aim\Capability\ImageGenerationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Prompt\PromptOverrideNormalizer;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Request\ImageGenerationRequest;
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
    private ?int $pageId = null;
    private bool $disableSystemPromptComposition = false;
    private array $systemPromptOverride = [];
    private int $maxTokens = 150;
    private float $temperature = 0.2;
    private string $extensionKey = '';
    private string $user = '';
    private string $providerNotation = '';
    private ?ResponseFormat $responseFormat = null;
    private array $metadata = [];

    // Vision-specific
    private string $imageData = '';
    private string $mimeType = '';

    // Translation-specific
    private string $translateText = '';
    private string $sourceLanguage = '';
    private string $targetLanguage = '';

    // Image generation-specific
    private string $referenceImageData = '';
    private string $referenceMimeType = '';
    private array $imageOptions = [];
    private int $imageCount = 1;

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

    /**
     * Generate an image. Use prompt() to set the description.
     *
     * No system-role channel exists for image generation: forPage()'s
     * resolved tone/fragments and systemPromptOverride() both get appended
     * as literal, visible text onto prompt() instead of a separate
     * instruction, since providers take a single prompt string here.
     */
    public function image(): self
    {
        $this->type = 'imageGeneration';
        return $this;
    }

    /**
     * Guide image generation with a reference image (image-to-image / style transfer).
     */
    public function referenceImage(string $imageData, string $mimeType): self
    {
        $this->referenceImageData = $imageData;
        $this->referenceMimeType = $mimeType;
        return $this;
    }

    /**
     * Provider-specific options passed through as-is, merged into whatever's
     * already set (e.g. ->options(['size' => '1024x1024', 'quality' => 'high'])).
     */
    public function options(array $options): self
    {
        $this->imageOptions = [...$this->imageOptions, ...$options];
        return $this;
    }

    /**
     * Number of images to generate in one request.
     */
    public function count(int $count): self
    {
        $this->imageCount = $count;
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

    /**
     * Anchor this request to a page, so TonePromptCompositionMiddleware
     * can resolve the page-tree tone-of-voice prompt for it. Without this,
     * the global default (Extension Configuration) applies instead.
     */
    public function forPage(?int $pageId): self
    {
        $this->pageId = $pageId;
        return $this;
    }

    /**
     * Opt out of all automatic prompt composition (page/TSconfig/user/registry
     * fragments, provider addendum): whatever systemPrompt()/prompt() was set
     * is sent as-is.
     */
    public function disableSystemPromptComposition(bool $disable = true): self
    {
        $this->disableSystemPromptComposition = $disable;
        return $this;
    }

    /**
     * Supply fragment(s) that totally replace the automatic tone/user/registry/
     * addendum resolution for this call, still composed with prompt()/
     * systemPrompt(), but nothing else. Unlike disableSystemPromptComposition(),
     * this still runs composition, just against these fragments instead of
     * the resolved ones.
     */
    public function systemPromptOverride(string|array $override): self
    {
        $this->systemPromptOverride = PromptOverrideNormalizer::normalize($override);
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
     * Attach caller-supplied metadata (e.g. 'aiLabel', read by EXT:ai_label's
     * own optional middleware), merged into whatever's already set. Never
     * overrides the 'extension' key from() sets, since that one already has
     * its own dedicated meaning.
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = [...$this->metadata, ...$metadata];
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
        $metadata = $this->metadata;
        if ($this->extensionKey !== '') {
            $metadata['extension'] = $this->extensionKey;
        }

        return match ($this->type) {
            'vision' => $this->sendVision($metadata),
            'text' => $this->sendText($metadata),
            'translation' => $this->sendTranslation($metadata),
            'imageGeneration' => $this->sendImageGeneration($metadata),
            default => throw new \LogicException(
                'No request type set. Call vision(), text(), translate(), or image() before send().',
                1773874300,
            ),
        };
    }

    private function sendImageGeneration(array $metadata): TextResponse
    {
        $capabilityClass = ImageGenerationCapableInterface::class;
        $resolvedProvider = $this->resolve($capabilityClass);
        $request = new ImageGenerationRequest(
            configuration: $resolvedProvider->configuration,
            prompt: $this->prompt,
            pageId: $this->pageId,
            disableAutomaticSystemPrompt: $this->disableSystemPromptComposition,
            systemPromptOverride: $this->systemPromptOverride,
            referenceImageData: $this->referenceImageData,
            referenceMimeType: $this->referenceMimeType,
            options: $this->imageOptions,
            count: $this->imageCount,
            user: $this->user,
            metadata: $metadata,
        );
        return $this->pipeline->dispatchWithFallback(
            $request,
            $this->providerResolver->buildFallbackChain($capabilityClass),
        );
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
            pageId: $this->pageId,
            disableAutomaticSystemPrompt: $this->disableSystemPromptComposition,
            systemPromptOverride: $this->systemPromptOverride,
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
            pageId: $this->pageId,
            disableAutomaticSystemPrompt: $this->disableSystemPromptComposition,
            systemPromptOverride: $this->systemPromptOverride,
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
            pageId: $this->pageId,
            disableAutomaticSystemPrompt: $this->disableSystemPromptComposition,
            systemPromptOverride: $this->systemPromptOverride,
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
