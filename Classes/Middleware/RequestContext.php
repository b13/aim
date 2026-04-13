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
}
