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
use B13\Aim\Response\TextResponse;

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

        if ($response->isSuccessful() && $configuration->uid > 0 && $response->usage->cost > 0) {
            $this->repository->updateTotalCost($configuration->uid, $response->usage->cost);
        }

        return $response;
    }
}
