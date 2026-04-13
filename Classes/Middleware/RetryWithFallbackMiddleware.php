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
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\FallbackChain;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;
use Psr\Log\LoggerInterface;

/**
 * Retries failed AI requests with fallback providers.
 *
 * Wraps the entire middleware chain (highest priority). If the primary
 * provider fails, iterates through the fallback chain and retries with
 * each alternative provider until one succeeds.
 *
 * The fallback chain is injected automatically by AiMiddlewarePipeline
 * when using dispatchWithFallback(). Each fallback attempt goes through
 * the full inner middleware chain (logging, cost tracking, etc.), so
 * failed attempts are logged individually.
 */
#[AsAiMiddleware(priority: 100)]
final class RetryWithFallbackMiddleware implements AiMiddlewareInterface
{
    private ?FallbackChain $fallbackChain = null;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function setFallbackChain(FallbackChain $chain): void
    {
        $this->fallbackChain = $chain;
    }

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        try {
            $response = $next->handle($request, $provider, $configuration);
            if ($response->isSuccessful()) {
                return $response;
            }
        } catch (\Throwable $e) {
            $response = new TextResponse('', errors: [$e->getMessage()]);
        }

        // No fallback chain or no fallbacks available — return the failed response
        if ($this->fallbackChain === null || count($this->fallbackChain) <= 1) {
            return $response;
        }

        // Try each fallback provider
        $originalError = $response->errors[0] ?? 'Unknown error';
        foreach ($this->fallbackChain->getFallbacks() as $fallback) {
            $this->logger->info(sprintf(
                'AI provider "%s" failed, trying fallback "%s".',
                $configuration->providerIdentifier,
                $fallback->manifest->identifier,
            ));

            // Signal to the logging middleware via per-request context
            $next->context->fallbackInfo = [
                'from' => $configuration->providerIdentifier . ':' . $configuration->model,
                'to' => $fallback->configuration->providerIdentifier . ':' . $fallback->configuration->model,
                'reason' => sprintf(
                    'Fallback from "%s" (%s): %s',
                    $configuration->providerIdentifier,
                    $configuration->model,
                    $originalError,
                ),
            ];

            try {
                $fallbackResponse = $next->handle(
                    $request,
                    $fallback->manifest->getInstance(),
                    $fallback->configuration,
                );
                if ($fallbackResponse->isSuccessful()) {
                    return $fallbackResponse;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'Fallback provider "%s" also failed: %s',
                    $fallback->manifest->identifier,
                    $e->getMessage(),
                ));
            }
        }

        // All fallbacks exhausted — return the original failed response
        return $response;
    }
}
