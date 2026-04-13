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
 * Wraps the next handler in the middleware chain.
 *
 * Passed to each middleware so it can delegate to the remaining chain.
 */
final class AiMiddlewareHandler
{
    /** @var callable(AiRequestInterface, AiProviderInterface, ProviderConfiguration): TextResponse */
    private $handler;

    /**
     * Per-request context shared across all middleware in a single dispatch.
     * Created once per pipeline dispatch, never shared between requests.
     */
    public readonly RequestContext $context;

    /**
     * @param callable(AiRequestInterface, AiProviderInterface, ProviderConfiguration): TextResponse $handler
     */
    public function __construct(callable $handler, ?RequestContext $context = null)
    {
        $this->handler = $handler;
        $this->context = $context ?? new RequestContext();
    }

    public function handle(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
    ): TextResponse {
        return ($this->handler)($request, $provider, $configuration);
    }
}
