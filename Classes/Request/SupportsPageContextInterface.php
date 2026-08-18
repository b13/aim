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

use B13\Aim\Prompt\PromptFragmentScope;

/**
 * Contract for request types that can be anchored to a page for
 * page-tree tone-of-voice resolution. Implemented both by
 * SupportsSystemPromptInterface (chat-shaped requests, composed into a
 * system-role message) and directly by ImageGenerationRequest (which has
 * no system-role channel and composes into `prompt` instead).
 */
interface SupportsPageContextInterface
{
    /**
     * The page this request is anchored to, for page-tree tone-of-voice
     * resolution. Null means no page context was supplied (e.g. alt-text
     * generation for sys_file_metadata, which has no meaningful pid) —
     * TonePromptCompositionMiddleware falls back to the global default
     * in that case. 0 is a real, distinct page id (the tree root), so it
     * must not be used as an "unset" sentinel.
     */
    public function getPageId(): ?int;

    /**
     * When true, both TonePromptCompositionMiddleware and
     * ProviderAddendumMiddleware skip all automatic composition entirely
     * (page/TSconfig/user/registry fragments, provider addendum),
     * whatever systemPrompt/prompt the caller already set is sent as-is.
     * Lets a caller opt out of everything AiM would otherwise add, e.g.
     * for a diagnostic call that needs an uncontaminated prompt.
     */
    public function isAutomaticPromptCompositionDisabled(): bool;

    /**
     * Caller-supplied fragment(s) that, when non-empty, totally replace the
     * automatic tone/user/registry/addendum resolution (layers 2-5) for this
     * call. Only the caller's own base prompt/systemPrompt (layer 1) plus
     * these fragments are composed. Unlike isAutomaticPromptCompositionDisabled(),
     * which yields a pure passthrough, this still runs composition, just
     * against caller-supplied content instead of resolved content. Empty
     * (the default) means "no override, resolve normally".
     *
     * @return list<string>
     */
    public function getSystemPromptOverride(): array;

    /**
     * Which fragment scope this request type matches against: DB/TSconfig/
     * registry fragments tagged with this scope (or "all") apply to it.
     * Self-declared per class rather than looked up from a central map, so a
     * new request type implementing this interface (in aim or a third-party
     * extension) can never be missed by TonePromptCompositionMiddleware —
     * there's no separate registry that could fall out of sync.
     */
    public function getPromptFragmentScope(): PromptFragmentScope;
}
