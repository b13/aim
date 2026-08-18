<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Governance\PrivacyLevel;
use B13\Aim\Prompt\PromptFragmentScope;

final class ImageGenerationRequest implements AiRequestInterface, SupportsPageContextInterface
{
    /**
     * @param string $referenceImageData Base64-encoded reference image. When set, the
     *        provider is asked to use it as a style/subject reference (image-to-image)
     *        instead of generating from the prompt alone. Requires $referenceMimeType.
     * @param array<string, mixed> $options Provider-specific options passed through as-is
     *        (e.g. "size", "quality", "background", "output_format", "negative_prompt").
     *        Left generic since valid keys/values differ per provider and model.
     * @param int $count Number of images to generate in one request.
     */
    public function __construct(
        public readonly ProviderConfiguration $configuration,
        public readonly string $prompt,
        public readonly ?int $pageId = null,
        public readonly bool $disableAutomaticSystemPrompt = false,
        public readonly array $systemPromptOverride = [],
        public readonly string $referenceImageData = '',
        public readonly string $referenceMimeType = '',
        public readonly array $options = [],
        public readonly int $count = 1,
        public readonly string $user = '',
        public readonly array $metadata = [],
        public readonly ?PrivacyLevel $privacyLevelOverride = null,
    ) {}

    public function getConfiguration(): ProviderConfiguration
    {
        return $this->configuration;
    }

    public function getPageId(): ?int
    {
        return $this->pageId;
    }

    public function isAutomaticPromptCompositionDisabled(): bool
    {
        return $this->disableAutomaticSystemPrompt;
    }

    public function getSystemPromptOverride(): array
    {
        return $this->systemPromptOverride;
    }

    public function getPromptFragmentScope(): PromptFragmentScope
    {
        return PromptFragmentScope::ImageGeneration;
    }

    /**
     * Return a copy of this request with a different prompt. Used by
     * TonePromptCompositionMiddleware/ProviderAddendumMiddleware to splice
     * in the resolved tone-of-voice/watermark instructions after the
     * caller's own prompt.
     */
    public function withPrompt(string $prompt): static
    {
        return new static(...array_merge(
            get_object_vars($this),
            ['prompt' => $prompt],
        ));
    }

    public function withConfiguration(ProviderConfiguration $configuration): static
    {
        return new static(...array_merge(
            get_object_vars($this),
            ['configuration' => $configuration],
        ));
    }

    public function withMetadata(array $additional): static
    {
        return new static(...array_merge(
            get_object_vars($this),
            ['metadata' => [...$this->metadata, ...$additional]],
        ));
    }

    public function withPrivacyLevel(PrivacyLevel $level): static
    {
        return new static(...array_merge(
            get_object_vars($this),
            ['privacyLevelOverride' => $level],
        ));
    }

    public function getPrivacyLevelOverride(): ?PrivacyLevel
    {
        return $this->privacyLevelOverride;
    }
}
