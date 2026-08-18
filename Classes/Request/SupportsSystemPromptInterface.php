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

/**
 * Contract for request types that carry a system prompt and can be
 * anchored to a page for tone-of-voice resolution.
 *
 * Implemented by VisionRequest, TextGenerationRequest, TranslationRequest,
 * ConversationRequest, and ToolCallingRequest . Not by EmbeddingRequest,
 * which has no system prompt concept, and not by ImageGenerationRequest,
 * which has no system-role channel at all (image generation APIs take a
 * single prompt string)- It implements SupportsPageContextInterface
 * directly instead and composes into `prompt` via its own withPrompt().
 * Kept as a separate interface (rather than growing AiRequestInterface) so
 * EmbeddingRequest isn't forced to implement a method that doesn't apply.
 */
interface SupportsSystemPromptInterface extends SupportsPageContextInterface
{
    public function getSystemPrompt(): string;

    /**
     * Return a copy of this request with a different system prompt. Used
     * by TonePromptCompositionMiddleware to inject the resolved
     * tone-of-voice prompt, and by ProviderAddendumMiddleware to append the
     * provider-specific addendum on top of that.
     */
    public function withSystemPrompt(string $systemPrompt): static;
}
