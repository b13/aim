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
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use B13\Aim\Response\ToolCallingResponse;

/**
 * Tracks cumulative cost per provider configuration.
 *
 * After each successful response, adds the request cost to the
 * configuration's total_cost field. Skips ephemeral configurations (uid=0).
 */
#[AsAiMiddleware(priority: -800)]
final class CostTrackingMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly ProviderConfigurationRepository $repository,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $response = $next->handle($request, $provider, $configuration);

        if (($response instanceof ConversationResponse || $response instanceof ToolCallingResponse) && $response->isStreaming()) {
            // usage->cost is a placeholder until the caller drains the stream,
            // reading the real cost off the same iterator once it's actually known.
            $this->deferCostTracking($response, $configuration);
            return $response;
        }

        if ($response->isSuccessful() && $configuration->uid > 0 && $response->usage->cost > 0) {
            $this->repository->updateTotalCost($configuration->uid, $response->usage->cost);
        }

        return $response;
    }

    private function deferCostTracking(
        ConversationResponse|ToolCallingResponse $response,
        ProviderConfiguration $configuration,
    ): void {
        $streamIterator = $response->streamIterator;
        if (!$streamIterator instanceof StreamChunkIterator || $configuration->uid <= 0) {
            return;
        }

        register_shutdown_function(
            function () use ($streamIterator, $configuration): void {
                $this->applyStreamedCost($streamIterator, $configuration->uid);
            },
        );
    }

    /**
     * Reads the real, final cost off the iterator. Only known once the
     * caller has drained the stream and applies it. Split out from
     * deferCostTracking() so the actual update logic is testable without
     * waiting for PHP's shutdown phase to invoke the registered closure.
     */
    private function applyStreamedCost(StreamChunkIterator $streamIterator, int $configurationUid): void
    {
        $cost = $streamIterator->getUsage()->cost;
        if ($cost > 0) {
            $this->repository->updateTotalCost($configurationUid, $cost);
        }
    }
}
