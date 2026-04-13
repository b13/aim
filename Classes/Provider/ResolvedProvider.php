<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Provider;

use B13\Aim\Capability\AiCapabilityInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;

/**
 * A provider that has been matched to a specific configuration.
 *
 * This is the result of provider resolution: it pairs a provider's manifest
 * (identifier, capabilities, models) with a concrete configuration record
 * (API key, model, cost settings). Everything needed to dispatch a request.
 *
 * Created by ProviderResolver and passed to the middleware pipeline.
 */
final class ResolvedProvider
{
    public function __construct(
        public readonly AiProviderManifest $manifest,
        public readonly ProviderConfiguration $configuration,
    ) {}

    /**
     * Returns the provider instance cast to the requested capability.
     *
     * @template T of AiCapabilityInterface
     * @param class-string<T> $capabilityFqcn
     * @return T
     */
    public function getCapability(string $capabilityFqcn): AiCapabilityInterface
    {
        $instance = $this->manifest->getInstance();
        if (!$instance instanceof $capabilityFqcn) {
            throw new \LogicException(sprintf(
                'Provider "%s" does not implement capability "%s".',
                $this->manifest->identifier,
                $capabilityFqcn,
            ), 1773874285);
        }
        return $instance;
    }
}
