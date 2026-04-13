<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Registry;

use B13\Aim\Capability\AiCapabilityInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class AiProviderRegistry
{
    /** @var AiProviderManifest[] */
    protected array $providers = [];

    public function addProvider(AiProviderManifest $provider): void
    {
        $this->providers[$provider->identifier] = $provider;
    }

    /** @return AiProviderManifest[] */
    public function getProviders(): array
    {
        return $this->providers;
    }

    public function hasProvider(string $identifier): bool
    {
        return isset($this->providers[$identifier]);
    }

    public function getProvider(string $identifier): AiProviderManifest
    {
        if (!$this->hasProvider($identifier)) {
            throw new \InvalidArgumentException('Provider "' . $identifier . '" is not registered.', 1773698665);
        }
        return $this->providers[$identifier];
    }

    /**
     * @param class-string<AiCapabilityInterface> $capabilityFqcn
     * @return AiProviderManifest[]
     */
    public function getProvidersByCapability(string $capabilityFqcn): array
    {
        return array_filter(
            $this->providers,
            static fn(AiProviderManifest $provider): bool => $provider->hasCapability($capabilityFqcn)
        );
    }
}
