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

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Event\AfterAiResponseEvent;
use B13\Aim\Event\BeforeAiRequestEvent;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches BeforeAiRequestEvent and AfterAiResponseEvent.
 *
 * Runs close to the core dispatch so event listeners see the final
 * request state and the raw provider response.
 */
#[AsAiMiddleware(priority: -900)]
final class EventDispatchMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AiProviderRegistry $registry,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $manifest = $this->registry->getProvider($configuration->providerIdentifier);

        $this->eventDispatcher->dispatch(
            new BeforeAiRequestEvent($request, $manifest, $configuration)
        );

        $response = $next->handle($request, $provider, $configuration);

        $this->eventDispatcher->dispatch(
            new AfterAiResponseEvent($response, $request, $manifest, $configuration)
        );

        return $response;
    }
}
