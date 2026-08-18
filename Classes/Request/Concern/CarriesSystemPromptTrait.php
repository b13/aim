<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request\Concern;

/**
 * Shared implementation for SupportsSystemPromptInterface
 */
trait CarriesSystemPromptTrait
{
    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function withSystemPrompt(string $systemPrompt): static
    {
        return new static(...array_merge(
            get_object_vars($this),
            ['systemPrompt' => $systemPrompt],
        ));
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
}
