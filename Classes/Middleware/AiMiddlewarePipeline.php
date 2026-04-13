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
use B13\Aim\Provider\FallbackChain;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Assembles and executes the AI middleware chain.
 *
 * This is the main entry point for dispatching AI requests through
 * the middleware pipeline. Consumers should use this instead of
 * calling providers directly.
 *
 * Usage:
 *   $resolvedProvider = $resolver->resolveForCapability(ConversationCapableInterface::class);
 *   $response = $pipeline->dispatch($chatRequest, $resolvedProvider);
 *
 * With fallback:
 *   $chain = $resolver->buildFallbackChain(ConversationCapableInterface::class);
 *   $response = $pipeline->dispatchWithFallback($chatRequest, $chain);
 */
#[Autoconfigure(public: true)]
final class AiMiddlewarePipeline
{
    /**
     * @param list<AiMiddlewareInterface> $middlewares Pre-sorted by priority (injected by compiler pass)
     */
    public function __construct(
        private readonly array $middlewares = [],
    ) {}

    /**
     * Dispatch an AI request through the middleware chain.
     */
    public function dispatch(AiRequestInterface $request, ResolvedProvider $resolvedProvider): TextResponse
    {
        $provider = $resolvedProvider->manifest->getInstance();
        $configuration = $resolvedProvider->configuration;

        return $this->buildChain(null)->handle($request, $provider, $configuration);
    }

    /**
     * Dispatch with automatic fallback on provider failure.
     *
     * The FallbackChain is passed to RetryWithFallbackMiddleware
     * so it can retry with alternative providers if the primary fails.
     */
    public function dispatchWithFallback(AiRequestInterface $request, FallbackChain $chain): TextResponse
    {
        $primary = $chain->getPrimary();
        $provider = $primary->manifest->getInstance();
        $configuration = $primary->configuration;

        return $this->buildChain($chain)->handle($request, $provider, $configuration);
    }

    private function buildChain(?FallbackChain $fallbackChain): AiMiddlewareHandler
    {
        // One context per dispatch cycle, shared by all middleware in the chain
        $context = new RequestContext();

        // Start with a no-op terminal handler (CoreDispatchMiddleware handles actual dispatch)
        $handler = new AiMiddlewareHandler(
            static function () {
                throw new \LogicException(
                    'AI middleware chain reached the end without dispatching. '
                    . 'Ensure CoreDispatchMiddleware is registered.',
                    1773874261
                );
            },
            $context,
        );

        // Build chain from inside out (reverse iteration since middlewares are sorted highest-priority-first)
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = $handler;

            // Inject fallback chain into the retry middleware for this dispatch cycle
            if ($fallbackChain !== null && $middleware instanceof RetryWithFallbackMiddleware) {
                $handler = new AiMiddlewareHandler(
                    static function (AiRequestInterface $req, AiProviderInterface $prov, ProviderConfiguration $conf) use ($middleware, $next, $fallbackChain): TextResponse {
                        $middleware->setFallbackChain($fallbackChain);
                        return $middleware->process($req, $prov, $conf, $next);
                    },
                    $context,
                );
            } else {
                $handler = new AiMiddlewareHandler(
                    static fn(AiRequestInterface $req, AiProviderInterface $prov, ProviderConfiguration $conf): TextResponse
                        => $middleware->process($req, $prov, $conf, $next),
                    $context,
                );
            }
        }

        return $handler;
    }
}
