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
use B13\Aim\Request\Message\AbstractMessage;

final class ConversationRequest implements AiRequestInterface
{
    /**
     * @param list<AbstractMessage> $messages
     */
    public function __construct(
        public readonly ProviderConfiguration $configuration,
        public readonly array $messages,
        public readonly string $systemPrompt = '',
        public readonly ?ResponseFormat $responseFormat = null,
        public readonly int $maxTokens = 1000,
        public readonly float $temperature = 0.7,
        public readonly string $user = '',
        public readonly array $metadata = [],
        public readonly bool $stream = false,
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
}
