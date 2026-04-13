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

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Creates ProviderConfiguration instances from different sources.
 *
 * Supports:
 * - DB records (primary, via ProviderConfigurationRepository)
 * - TYPO3 Site Settings YAML as fallback
 * - Ephemeral in-memory configs from provider:model notation
 *
 * Example site settings (config/sites/<identifier>/settings.yaml):
 *
 *   ai:
 *     provider: openai
 *     apiKey: sk-...
 *     model: gpt-4o
 */
final class ProviderConfigurationFactory
{
    /**
     * Create a configuration from a database row.
     */
    public static function fromRow(array $row): ProviderConfiguration
    {
        return new ProviderConfiguration($row);
    }

    /**
     * Create a copy of an existing configuration with a different model.
     *
     * Used by auto model switch: reuses the API key from the original
     * configuration but targets a different model that supports the
     * requested capability.
     */
    public static function withModelOverride(
        ProviderConfiguration $config,
        string $model,
        string $reason,
    ): ProviderConfiguration {
        return new ProviderConfiguration(array_merge($config->row, [
            'uid' => $config->uid,
            'model' => $model,
            'title' => $config->title . ' (auto: ' . $model . ')',
            '_auto_model_switch' => true,
            '_auto_model_switch_from' => $config->model,
            '_auto_model_switch_reason' => $reason,
        ]));
    }

    /**
     * Create a configuration from TYPO3 Site Settings.
     *
     * This serves as a fallback when no DB-managed configuration exists,
     * allowing simple setups via site config YAML files.
     */
    public static function fromSiteSettings(Site $site, string $prefix = 'ai'): ?ProviderConfiguration
    {
        $settings = $site->getSettings();
        $providerKey = $prefix . '.provider';
        $apiKeyKey = $prefix . '.apiKey';

        if (!$settings->has($providerKey) || !$settings->has($apiKeyKey)) {
            return null;
        }

        $apiKey = $settings->get($apiKeyKey);
        if ($apiKey === '' || $apiKey === null) {
            return null;
        }

        return new ProviderConfiguration([
            'uid' => 0,
            'ai_provider' => (string)$settings->get($providerKey),
            'title' => 'Site: ' . $site->getIdentifier(),
            'api_key' => (string)$apiKey,
            'model' => (string)($settings->has($prefix . '.model') ? $settings->get($prefix . '.model') : ''),
            'cost_currency' => 'USD',
            'total_cost' => 0,
            'default' => 0,
            'disabled' => 0,
        ]);
    }

    /**
     * Create an ephemeral configuration from explicit values.
     *
     * Used by the compact provider:model notation when no DB record exists.
     * Ephemeral configs have uid=0 and are not persisted.
     */
    public static function ephemeral(
        string $providerIdentifier,
        string $model,
        string $apiKey,
        string $title = '',
    ): ProviderConfiguration {
        return new ProviderConfiguration([
            'uid' => 0,
            'ai_provider' => $providerIdentifier,
            'title' => $title ?: $providerIdentifier . ':' . $model,
            'api_key' => $apiKey,
            'model' => $model,
            'cost_currency' => 'USD',
            'total_cost' => 0,
            'default' => 0,
            'disabled' => 0,
        ]);
    }
}
