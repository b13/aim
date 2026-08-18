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
use B13\Aim\Request\Concern\CarriesSystemPromptTrait;

final class VisionRequest implements AiRequestInterface, SupportsSystemPromptInterface
{
    use CarriesSystemPromptTrait;

    public function getPromptFragmentScope(): PromptFragmentScope
    {
        return PromptFragmentScope::Vision;
    }

    public function __construct(
        public readonly ProviderConfiguration $configuration,
        public readonly string $imageData,
        public readonly string $mimeType,
        public readonly string $prompt,
        public readonly string $systemPrompt = '',
        public readonly ?int $pageId = null,
        public readonly bool $disableAutomaticSystemPrompt = false,
        public readonly array $systemPromptOverride = [],
        public readonly int $maxTokens = 150,
        public readonly float $temperature = 0.2,
        public readonly string $user = '',
        public readonly array $metadata = [],
        public readonly ?PrivacyLevel $privacyLevelOverride = null,
    ) {}

    public function getConfiguration(): ProviderConfiguration
    {
        return $this->configuration;
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
