<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Middleware;

use B13\Aim\Governance\PrivacyLevel;

/**
 * Per-request context object passed through the middleware chain.
 *
 * Replaces static variables for cross-middleware communication.
 * Each dispatch creates a fresh instance, so concurrent requests
 * never share state.
 */
final class RequestContext
{
    /** @var array{score: float, label: string, reason: string}|null */
    public ?array $complexity = null;

    /** @var array{from: string, to: string, reason: string}|null */
    public ?array $fallbackInfo = null;

    /**
     * Primary key of the row written by RequestLoggingMiddleware,
     * surfaced so GraderMiddleware can attach a grade to it later.
     */
    public ?int $logUid = null;

    /**
     * The deduped list of parts TonePromptCompositionMiddleware composed
     * the tone-of-voice prompt from (caller instruction + page/user/registry
     * fragments, or the override list). Surfaced so ProviderAddendumMiddleware
     * can skip appending its addendum if it's an exact duplicate of a part
     * already included, preserving full cross-phase dedup even though the
     * two middlewares run at different priorities. Empty when the tone
     * phase didn't run (e.g. composition disabled) or produced nothing.
     *
     * @var list<string>
     */
    public array $composedPromptParts = [];

    /**
     * Whether TonePromptCompositionMiddleware found a non-empty
     * systemPromptOverride and used it instead of automatic composition.
     * Surfaced so ProviderAddendumMiddleware can skip appending its
     * addendum in that case (a full override means total, not "everything
     * except the bit AiM insists on") without re-normalizing
     * getSystemPromptOverride() itself a second time for the same request.
     */
    public bool $promptOverrideApplied = false;

    /**
     * Set by AccessControlMiddleware when it refuses a request. That refusal is
     * about the caller, not the provider, so RetryWithFallbackMiddleware must
     * not read it as "this provider is down" and sweep the whole chain: every
     * hop would be refused for the same reason, and the user would be shown the
     * failure attributed to the last configuration tried.
     */
    public bool $governanceDenied = false;

    /**
     * Whether the rate limiter has already counted this dispatch.
     * AccessControlMiddleware runs inside RetryWithFallbackMiddleware, so it is
     * entered once per fallback hop, and counting each of them would make the
     * effective limit the configured one divided by the chain length.
     */
    public bool $rateLimitCounted = false;

    /**
     * The strictest privacy level any configuration in this dispatch asked for.
     *
     * The level is otherwise read from the configuration of the current
     * attempt, so a primary set to log nothing would fall back to a `standard`
     * configuration and that attempt would write the full prompt. The
     * originating configuration's choice has to follow the request through the
     * chain, the same way the TSconfig and per-request override do.
     */
    public ?PrivacyLevel $privacyFloor = null;
}
