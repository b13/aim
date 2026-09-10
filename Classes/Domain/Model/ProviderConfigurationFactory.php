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

use B13\Aim\Provider\EndpointCredential;
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
 *     endpoint: https://gateway.example.com/v1   # self-hosted or a gateway
 *     model: gpt-4o
 *
 * Either apiKey or endpoint is enough on its own. Note that unlike the database
 * path, these values are not encrypted: a site settings file is configuration,
 * usually version-controlled, so a credential in there is readable by anyone
 * who can read the repository.
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
        $providerSetting = $prefix . '.provider';
        $apiKeySetting = $prefix . '.apiKey';
        $endpointSetting = $prefix . '.endpoint';
        $modelSetting = $prefix . '.model';

        // Read through get() rather than has(): on TYPO3 12.4 has() inspects
        // only the unflattened top level of the settings array, while get()
        // consults the flattened map.
        $provider = (string)($settings->get($providerSetting) ?? '');
        if ($provider === '') {
            return null;
        }

        $apiKey = (string)($settings->get($apiKeySetting) ?? '');
        $endpoint = (string)($settings->get($endpointSetting) ?? '');

        // A self-hosted provider needs an endpoint and no credential, so either
        // one on its own is a usable configuration. Requiring apiKey made an
        // endpoint-only site configuration impossible to express.
        if ($apiKey === '' && $endpoint === '') {
            return null;
        }

        [$endpoint, $apiKey] = self::splitCredentialOutOfEndpoint($endpoint, $apiKey, $site->getIdentifier(), $prefix);

        return new ProviderConfiguration([
            'uid' => 0,
            'ai_provider' => $provider,
            'title' => 'Site: ' . $site->getIdentifier(),
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'model' => (string)($settings->get($modelSetting) ?? ''),
            'cost_currency' => 'USD',
            'total_cost' => 0,
            'default' => 0,
            'disabled' => 0,
        ]);
    }

    /**
     * Site settings are the one path into a configuration that never goes
     * through DataHandler, so nothing had taken an inline credential out of the
     * endpoint here. Two consequences: the password stayed in the endpoint,
     * where a password in the URL also defeats the "either the URL or a header,
     * never both" guarantee, so a site that set both `ai.endpoint` with
     * userinfo and `ai.apiKey` sent two credentials to the host.
     *
     * Split like the DataHandler hook does, and with the same precedence: an
     * explicit `ai.apiKey` wins, because that is the setting meant for a
     * credential. The user half stays in the endpoint, which is what marks the
     * credential as belonging in the URL rather than in a header.
     *
     * @return array{0: string, 1: string} endpoint, credential
     */
    private static function splitCredentialOutOfEndpoint(
        string $endpoint,
        string $apiKey,
        string $siteIdentifier,
        string $prefix,
    ): array {
        if ($endpoint === '') {
            return [$endpoint, $apiKey];
        }

        $credential = EndpointCredential::split($endpoint);
        if ($credential === null) {
            return [$endpoint, $apiKey];
        }

        if ($apiKey === '') {
            return [$credential['url'], $credential['secret']];
        }

        // Both were configured and they disagree, so one is being dropped.
        // There is no form to surface that in, unlike the DataHandler path, so
        // it goes to the log.
        if ($apiKey !== $credential['secret']) {
            trigger_error(
                sprintf(
                    'Site "%s" configures a password in %s.endpoint and a separate %s.apiKey. '
                    . 'The apiKey setting is used and the password in the URL is ignored.',
                    $siteIdentifier,
                    $prefix,
                    $prefix,
                ),
                E_USER_WARNING,
            );
        }

        return [$credential['url'], $apiKey];
    }

    /**
     * An ephemeral configuration that borrows a stored one's credential and
     * endpoint, for the provider:model notation when no record has that model.
     *
     * The whole source row is merged rather than a hand-picked list of fields,
     * so a governance column added later is inherited without anyone having to
     * remember this method: taking the credential of a configuration means
     * taking its restrictions too, including be_groups, privacy_level and both
     * rerouting flags. uid stays 0 because nothing here is persisted.
     */
    public static function ephemeralFrom(
        ProviderConfiguration $source,
        string $providerIdentifier,
        string $model,
    ): ProviderConfiguration {
        return new ProviderConfiguration(array_merge($source->row, [
            'uid' => 0,
            'ai_provider' => $providerIdentifier,
            'model' => $model,
            'title' => $source->title . ' (' . $model . ')',
            'api_key' => $source->apiKey,
            'endpoint' => $source->endpoint,
        ]));
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
        string $endpoint = '',
    ): ProviderConfiguration {
        return new ProviderConfiguration([
            'uid' => 0,
            'ai_provider' => $providerIdentifier,
            'title' => $title ?: $providerIdentifier . ':' . $model,
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'model' => $model,
            'cost_currency' => 'USD',
            'total_cost' => 0,
            'default' => 0,
            'disabled' => 0,
        ]);
    }
}
