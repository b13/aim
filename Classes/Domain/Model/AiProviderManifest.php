<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Domain\Model;

use B13\Aim\Capability\AiCapabilityInterface;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ProviderFeatures;
use Psr\Container\ContainerInterface;

final class AiProviderManifest
{
    private ?AiProviderInterface $instance = null;

    /**
     * @param array<class-string<AiCapabilityInterface>> $capabilities
     * @param array<string, string> $supportedModels
     * @param array<string, list<class-string<AiCapabilityInterface>>> $modelCapabilities
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $name,
        public readonly string $description,
        public readonly string $iconIdentifier,
        public readonly array $supportedModels,
        public readonly array $capabilities,
        private readonly string $serviceName,
        private readonly ContainerInterface $container,
        public readonly ProviderFeatures $features = new ProviderFeatures(),
        public readonly array $modelCapabilities = [],
    ) {}

    public function getInstance(): AiProviderInterface
    {
        return $this->instance ??= $this->container->get($this->serviceName);
    }

    /**
     * Check if the provider supports a capability (provider-level).
     *
     * @param class-string<AiCapabilityInterface> $capabilityFqcn
     */
    public function hasCapability(string $capabilityFqcn): bool
    {
        return in_array($capabilityFqcn, $this->capabilities, true);
    }

    /**
     * Check if a specific model supports a capability.
     *
     * If the model is listed in modelCapabilities, only those capabilities apply.
     * Otherwise the model inherits all provider-level capabilities.
     *
     * @param class-string<AiCapabilityInterface> $capabilityFqcn
     */
    public function hasModelCapability(string $model, string $capabilityFqcn): bool
    {
        // No model-level overrides — all models inherit provider capabilities
        if ($this->modelCapabilities === []) {
            return $this->hasCapability($capabilityFqcn);
        }
        // Model explicitly listed — use only its declared capabilities
        if (isset($this->modelCapabilities[$model])) {
            return in_array($capabilityFqcn, $this->modelCapabilities[$model], true);
        }
        // Model not listed but modelCapabilities is populated:
        // Inherit all provider capabilities EXCEPT those that are exclusive
        // to listed models (i.e. capabilities not shared by any listed model
        // with the full provider capability set).
        // Simple heuristic: if a capability appears ONLY in modelCapabilities
        // entries (never alongside all other provider capabilities), it's
        // considered specialized and excluded from unlisted models.
        $specializedCapabilities = $this->getSpecializedCapabilities();
        if (in_array($capabilityFqcn, $specializedCapabilities, true)) {
            return false;
        }
        return $this->hasCapability($capabilityFqcn);
    }

    /**
     * Capabilities that appear in modelCapabilities but are NOT shared by
     * all listed models — these are specialized (e.g. embedding-only).
     *
     * @return list<class-string<AiCapabilityInterface>>
     */
    private function getSpecializedCapabilities(): array
    {
        if ($this->modelCapabilities === []) {
            return [];
        }
        // Collect capabilities that ONLY appear in models with a reduced set
        $allProviderCapabilities = count($this->capabilities);
        $specialized = [];
        foreach ($this->modelCapabilities as $caps) {
            if (count($caps) < $allProviderCapabilities) {
                foreach ($caps as $cap) {
                    $specialized[$cap] = true;
                }
            }
        }
        // Remove capabilities that also appear in models with the full set
        foreach ($this->modelCapabilities as $caps) {
            if (count($caps) >= $allProviderCapabilities) {
                foreach ($caps as $cap) {
                    unset($specialized[$cap]);
                }
            }
        }
        return array_keys($specialized);
    }

    /**
     * Find all models from this provider that support the given capability.
     *
     * @param class-string<AiCapabilityInterface> $capabilityFqcn
     * @return list<string> Model IDs supporting the capability
     */
    public function findModelsForCapability(string $capabilityFqcn): array
    {
        $models = [];
        foreach ($this->modelCapabilities as $model => $capabilities) {
            if (in_array($capabilityFqcn, $capabilities, true)) {
                $models[] = $model;
            }
        }
        return $models;
    }

    /**
     * Find a single model from this provider that supports the given capability.
     *
     * Convenience wrapper around findModelsForCapability(). Returns the model
     * with the fewest capabilities (most specialized = typically cheapest).
     *
     * @param class-string<AiCapabilityInterface> $capabilityFqcn
     * @return string|null The model ID, or null if no model supports it
     */
    public function findModelForCapability(string $capabilityFqcn): ?string
    {
        $candidates = $this->findModelsForCapability($capabilityFqcn);
        if ($candidates === []) {
            return null;
        }

        // Pick the model with the fewest capabilities (most specialized = typically cheapest).
        $best = null;
        $bestCapCount = PHP_INT_MAX;
        foreach ($candidates as $model) {
            $capCount = count($this->modelCapabilities[$model] ?? []);
            if ($capCount < $bestCapCount) {
                $best = $model;
                $bestCapCount = $capCount;
            }
        }
        return $best;
    }
}
