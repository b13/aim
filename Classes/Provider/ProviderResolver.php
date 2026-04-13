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
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Model\ProviderConfigurationFactory;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Exception\InvalidProviderNotationException;
use B13\Aim\Exception\ProviderNotFoundException;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Registry\DisabledModelRegistry;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves AI providers for a given capability or notation.
 *
 * Central entry point for finding a suitable provider + configuration pair.
 * Used by the Ai proxy, the fluent builder, and extensions that need direct
 * pipeline access.
 *
 * Handles:
 *
 * - Default resolution: finds the best enabled configuration for a capability
 * - UID-based resolution: resolves a specific configuration by database UID
 * - Notation-based resolution: parses "provider:model" strings (e.g. "openai:gpt-4.1")
 * - Wildcard notation: "openai:*" uses the first enabled config for that provider
 * - Site settings resolution: reads provider config from site YAML
 * - Fallback chains: builds ordered lists of all capable providers for retry
 * - Auto model switch: when no config directly supports a capability, finds an
 *   alternative model from the same provider using historical cost data
 *
 * Resolution order for default (no UID):
 *
 * 1. Default configuration marked as default in the database
 * 2. First enabled configuration that supports the capability
 * 3. Auto model switch: reuse an existing config's API key with a different model
 *
 * Respects disabled configurations, disabled models, and the auto model switch
 * setting (per config and per user via TSconfig).
 */
final class ProviderResolver
{
    public function __construct(
        private readonly AiProviderRegistry $registry,
        private readonly ProviderConfigurationRepository $configurationRepository,
        private readonly DisabledModelRegistry $disabledModelRegistry,
        private readonly RequestLogRepository $logRepository,
    ) {}

    /**
     * Resolve a provider for the given capability.
     *
     * @template T of AiCapabilityInterface
     * @param class-string<T> $capabilityFqcn
     */
    public function resolveForCapability(string $capabilityFqcn, ?int $configurationUid = null): ResolvedProvider
    {
        if ($configurationUid !== null) {
            $configuration = $this->configurationRepository->findByUid($configurationUid);
            if ($configuration === null) {
                throw new \RuntimeException('Provider configuration with uid ' . $configurationUid . ' not found.', 1773874270);
            }
            if ($configuration->disabled) {
                throw new \RuntimeException(sprintf(
                    'Provider configuration %d ("%s") is disabled.',
                    $configurationUid,
                    $configuration->title,
                ), 1773874283);
            }
            $manifest = $this->registry->getProvider($configuration->providerIdentifier);
            if (!$manifest->hasModelCapability($configuration->model, $capabilityFqcn)) {
                throw new \RuntimeException(sprintf(
                    'Provider "%s" with model "%s" (configuration %d) does not support capability "%s".',
                    $manifest->identifier,
                    $configuration->model,
                    $configurationUid,
                    $capabilityFqcn,
                ), 1773874271);
            }
            return new ResolvedProvider($manifest, $configuration);
        }

        // Find the best configuration: default first, then first capable
        $configurations = $this->configurationRepository->findAll();
        $firstCapable = null;
        foreach ($configurations as $configuration) {
            if ($configuration->disabled) {
                continue;
            }
            try {
                $manifest = $this->registry->getProvider($configuration->providerIdentifier);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!$manifest->hasModelCapability($configuration->model, $capabilityFqcn)) {
                continue;
            }
            if ($configuration->isDefault) {
                return new ResolvedProvider($manifest, $configuration);
            }
            $firstCapable ??= new ResolvedProvider($manifest, $configuration);
        }
        if ($firstCapable !== null) {
            return $firstCapable;
        }

        // No direct match — try auto model switch: find a config whose provider
        // has an alternative model supporting the requested capability. The config's
        // API key is reused with the alternative model.
        $switched = $this->tryAutoModelSwitch($capabilityFqcn, $configurations);
        if ($switched !== null) {
            return $switched;
        }

        throw new ProviderNotFoundException('No provider found for capability "' . $capabilityFqcn . '".', 1773874272);
    }

    /**
     * Resolve a provider using compact "provider:model" notation.
     *
     * Looks for an existing DB configuration matching the provider and model.
     * If none exists and an API key is given, creates an ephemeral configuration.
     * If none exists and no API key is given, uses the default config for that
     * provider and overrides the model.
     *
     * Examples:
     *   $resolver->resolveByString('openai:gpt-4o', ConversationCapableInterface::class)
     *   $resolver->resolveByString('openai:gpt-4.1-mini', VisionCapableInterface::class, 'sk-...')
     *
     * @template T of AiCapabilityInterface
     * @param class-string<T> $capabilityFqcn
     */
    public function resolveByString(string $notation, string $capabilityFqcn, ?string $apiKey = null): ResolvedProvider
    {
        [$providerIdentifier, $model] = $this->parseNotation($notation);

        if (!$this->registry->hasProvider($providerIdentifier)) {
            throw new ProviderNotFoundException(sprintf(
                'Provider "%s" from notation "%s" is not registered.',
                $providerIdentifier,
                $notation,
            ), 1773874273);
        }

        $manifest = $this->registry->getProvider($providerIdentifier);
        $checkModel = $model !== '*' ? $model : '';
        if (!$manifest->hasModelCapability($checkModel, $capabilityFqcn)) {
            throw new \RuntimeException(sprintf(
                'Provider "%s" with model "%s" does not support capability "%s".',
                $providerIdentifier,
                $model,
                $capabilityFqcn,
            ), 1773874274);
        }

        $configs = $this->configurationRepository->findByProviderIdentifier($providerIdentifier);

        // Wildcard model: "openai:*" — use the first enabled config as-is
        if ($model === '*') {
            foreach ($configs as $config) {
                if (!$config->disabled) {
                    return new ResolvedProvider($manifest, $config);
                }
            }
            throw new ProviderNotFoundException(sprintf(
                'No enabled configuration found for provider "%s".',
                $providerIdentifier,
            ), 1773874275);
        }

        // Try to find an existing DB config for this provider + model
        foreach ($configs as $config) {
            if ($config->model === $model && !$config->disabled) {
                return new ResolvedProvider($manifest, $config);
            }
        }

        // No exact match — if API key given, create ephemeral config
        if ($apiKey !== null) {
            $ephemeral = ProviderConfigurationFactory::ephemeral($providerIdentifier, $model, $apiKey);
            return new ResolvedProvider($manifest, $ephemeral);
        }

        // No API key — find any config for this provider and override the model
        foreach ($configs as $config) {
            if (!$config->disabled) {
                $overridden = ProviderConfigurationFactory::ephemeral(
                    $providerIdentifier,
                    $model,
                    $config->apiKey,
                    $config->title . ' (' . $model . ')',
                );
                return new ResolvedProvider($manifest, $overridden);
            }
        }

        throw new ProviderNotFoundException(sprintf(
            'No configuration found for provider "%s". Provide an API key or create a configuration record.',
            $providerIdentifier,
        ), 1773874275);
    }

    /**
     * Resolve a provider using TYPO3 Site Settings as configuration source.
     *
     * @template T of AiCapabilityInterface
     * @param class-string<T> $capabilityFqcn
     */
    public function resolveFromSiteSettings(string $capabilityFqcn, Site $site, string $settingsPrefix = 'ai'): ResolvedProvider
    {
        $configuration = ProviderConfigurationFactory::fromSiteSettings($site, $settingsPrefix);
        if ($configuration === null) {
            throw new \RuntimeException(sprintf(
                'Site "%s" has no AI provider configured (missing %s.provider or %s.apiKey in site settings).',
                $site->getIdentifier(),
                $settingsPrefix,
                $settingsPrefix,
            ), 1773874276);
        }

        if (!$this->registry->hasProvider($configuration->providerIdentifier)) {
            throw new ProviderNotFoundException(sprintf(
                'AI provider "%s" configured in site "%s" is not registered.',
                $configuration->providerIdentifier,
                $site->getIdentifier(),
            ), 1773874277);
        }

        $manifest = $this->registry->getProvider($configuration->providerIdentifier);
        if (!$manifest->hasModelCapability($configuration->model, $capabilityFqcn)) {
            throw new \RuntimeException(sprintf(
                'Provider "%s" with model "%s" (site "%s") does not support capability "%s".',
                $manifest->identifier,
                $configuration->model,
                $site->getIdentifier(),
                $capabilityFqcn,
            ), 1773874278);
        }

        return new ResolvedProvider($manifest, $configuration);
    }

    /**
     * Try multiple configurations in order, return the first that supports the capability.
     *
     * @template T of AiCapabilityInterface
     * @param class-string<T> $capabilityFqcn
     * @param list<int> $configurationUids
     */
    public function resolveWithFallback(string $capabilityFqcn, array $configurationUids): ResolvedProvider
    {
        $errors = [];
        foreach ($configurationUids as $uid) {
            try {
                return $this->resolveForCapability($capabilityFqcn, $uid);
            } catch (\Throwable $e) {
                $errors[] = sprintf('Config %d: %s', $uid, $e->getMessage());
            }
        }

        throw new ProviderNotFoundException(sprintf(
            'No viable provider found after trying %d configurations for capability "%s": %s',
            count($configurationUids),
            $capabilityFqcn,
            implode('; ', $errors),
        ), 1773874279);
    }

    /**
     * Build a FallbackChain of all providers supporting the given capability.
     *
     * Returns providers ordered by: default first, then by title.
     * Useful for building runtime fallback chains for the middleware pipeline.
     *
     * @template T of AiCapabilityInterface
     * @param class-string<T> $capabilityFqcn
     */
    public function buildFallbackChain(string $capabilityFqcn): FallbackChain
    {
        $resolved = $this->resolveAllForCapability($capabilityFqcn);
        if ($resolved === []) {
            throw new ProviderNotFoundException('No provider found for capability "' . $capabilityFqcn . '".', 1773874280);
        }

        return new FallbackChain($resolved[0], ...array_slice($resolved, 1));
    }

    /**
     * Resolve all providers that support a given capability.
     *
     * @template T of AiCapabilityInterface
     * @param class-string<T> $capabilityFqcn
     * @return list<ResolvedProvider>
     */
    public function resolveAllForCapability(string $capabilityFqcn): array
    {
        $defaults = [];
        $others = [];
        $configurations = $this->configurationRepository->findAll();
        foreach ($configurations as $configuration) {
            if ($configuration->disabled) {
                continue;
            }
            if ($this->disabledModelRegistry->isDisabled($configuration->providerIdentifier, $configuration->model)) {
                continue;
            }
            if (!$this->registry->hasProvider($configuration->providerIdentifier)) {
                continue;
            }
            $manifest = $this->registry->getProvider($configuration->providerIdentifier);
            if ($manifest->hasModelCapability($configuration->model, $capabilityFqcn)) {
                $provider = new ResolvedProvider($manifest, $configuration);
                if ($configuration->isDefault) {
                    $defaults[] = $provider;
                } else {
                    $others[] = $provider;
                }
            }
        }

        $result = array_merge($defaults, $others);

        // If no direct matches, try auto model switch
        if ($result === []) {
            $switched = $this->tryAutoModelSwitch($capabilityFqcn, $configurations);
            if ($switched !== null) {
                $result[] = $switched;
            }
        }

        return $result;
    }

    /**
     * Parse a "provider:model" notation string.
     *
     * @return array{0: string, 1: string} [providerIdentifier, model]
     */
    private function parseNotation(string $notation): array
    {
        if (!str_contains($notation, ':')) {
            throw new InvalidProviderNotationException(sprintf(
                'Invalid provider notation "%s". Expected format: "provider:model" (e.g. "openai:gpt-4o").',
                $notation,
            ), 1773874281);
        }

        $parts = explode(':', $notation, 2);
        $provider = trim($parts[0]);
        $model = trim($parts[1]);

        if ($provider === '' || $model === '') {
            throw new InvalidProviderNotationException(sprintf(
                'Invalid provider notation "%s". Both provider and model must be non-empty.',
                $notation,
            ), 1773874282);
        }

        return [$provider, $model];
    }

    /**
     * Try auto model switch: find a provider config whose configured model
     * doesn't support the capability, but the provider has an alternative
     * model that does. Reuse the config's API key with the alternative model.
     *
     * Guarded by:
     * - auto_model_switch flag on the configuration (admin can disable per config)
     * - aim.autoModelSwitch TSconfig (admin can disable per user/group)
     * - disabled model check (alternative model must not be disabled)
     * - be_groups check on the config (user must have access)
     *
     * @param list<ProviderConfiguration> $configurations
     */
    private function tryAutoModelSwitch(string $capabilityFqcn, array $configurations): ?ResolvedProvider
    {
        // Check user-level TSconfig override
        if (!$this->isAutoModelSwitchAllowedForUser()) {
            return null;
        }

        // Try default configs first, then others
        $sorted = [];
        foreach ($configurations as $config) {
            if ($config->disabled || !$config->autoModelSwitch) {
                continue;
            }
            if (!$this->registry->hasProvider($config->providerIdentifier)) {
                continue;
            }
            if ($this->disabledModelRegistry->isDisabled($config->providerIdentifier, $config->model)) {
                continue;
            }
            if ($config->isDefault) {
                array_unshift($sorted, $config);
            } else {
                $sorted[] = $config;
            }
        }

        foreach ($sorted as $config) {
            $manifest = $this->registry->getProvider($config->providerIdentifier);

            // Provider-level: does the provider support this capability at all?
            if (!$manifest->hasCapability($capabilityFqcn)) {
                continue;
            }

            // The configured model doesn't support it — find one that does
            if ($manifest->hasModelCapability($config->model, $capabilityFqcn)) {
                continue; // Model already supports it — should have been found earlier
            }

            $alternativeModel = $this->pickCheapestModel(
                $manifest->findModelsForCapability($capabilityFqcn),
                $config->providerIdentifier,
            );
            if ($alternativeModel === null) {
                continue;
            }

            // Create ephemeral config with the alternative model but same API key
            $reason = sprintf(
                'Model "%s" does not support %s, auto-switched to "%s"',
                $config->model,
                substr(strrchr($capabilityFqcn, '\\') ?: $capabilityFqcn, 1),
                $alternativeModel,
            );
            $switchedConfig = ProviderConfigurationFactory::withModelOverride($config, $alternativeModel, $reason);

            return new ResolvedProvider($manifest, $switchedConfig);
        }

        return null;
    }

    private function isAutoModelSwitchAllowedForUser(): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user === null) {
            return true; // CLI/frontend — allow
        }
        if ($user->isAdmin()) {
            return true;
        }
        $tsConfig = $user->getTSConfig()['aim.'] ?? [];
        $setting = $tsConfig['autoModelSwitch'] ?? '1';
        return (bool)(int)$setting;
    }

    /**
     * Pick the cheapest model from a list of candidates.
     *
     * Uses historical cost data from the request log when available.
     * Falls back to the first non-disabled candidate if no history exists.
     *
     * @param list<string> $candidates Model IDs
     */
    private function pickCheapestModel(array $candidates, string $providerIdentifier): ?string
    {
        // Filter out disabled models
        $candidates = array_filter(
            $candidates,
            fn(string $model) => !$this->disabledModelRegistry->isDisabled($providerIdentifier, $model),
        );
        if ($candidates === []) {
            return null;
        }

        // Try to rank by historical average cost (only successful requests)
        try {
            $profiles = $this->logRepository->getModelPerformanceProfile();
            $costMap = [];
            foreach ($profiles as $profile) {
                // Skip models with poor success rate or no successful requests
                if ((float)$profile['success_rate'] < 50.0 || (int)$profile['request_count'] < 1) {
                    continue;
                }
                $costMap[$profile['model_used']] = (float)$profile['avg_cost'];
            }

            // Find candidates with cost data, pick cheapest
            $withCost = [];
            foreach ($candidates as $model) {
                if (isset($costMap[$model])) {
                    $withCost[$model] = $costMap[$model];
                }
            }
            if ($withCost !== []) {
                asort($withCost);
                return array_key_first($withCost);
            }
        } catch (\Throwable) {
            // No log data available — fall through to fallback
        }

        // No usable cost data — exclude models with known failures
        try {
            $profiles = $this->logRepository->getModelPerformanceProfile();
            $failedModels = [];
            foreach ($profiles as $profile) {
                if ((float)$profile['success_rate'] < 50.0) {
                    $failedModels[$profile['model_used']] = true;
                }
            }
            $candidates = array_filter(
                $candidates,
                static fn(string $model) => !isset($failedModels[$model]),
            );
        } catch (\Throwable) {
        }

        // Return the first remaining candidate (fewest capabilities = most specialized)
        return $candidates === [] ? null : reset($candidates);
    }
}
