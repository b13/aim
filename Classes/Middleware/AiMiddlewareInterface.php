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

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;

/**
 * Middleware for intercepting AI request/response processing.
 *
 * Middlewares form a chain around the actual provider call. Each middleware
 * can modify the request before passing it on, or inspect / modify the
 * response on the way back. Common use cases: logging, caching, cost
 * tracking, rate limiting, fallback handling.
 */
interface AiMiddlewareInterface
{
    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse;
}
