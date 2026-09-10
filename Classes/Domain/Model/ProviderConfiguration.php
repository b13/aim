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

/**
 * Represents a provider configuration record from tx_aim_configuration.
 *
 * Maps the database row to typed properties: provider identifier, API key,
 * model, cost tracking, and governance settings (group restrictions, privacy
 * level, rerouting protection, auto model switch).
 *
 * The raw $row is preserved for access to provider-specific fields
 * (e.g. max_tokens, input_token_cost) via the get() method.
 */
final class ProviderConfiguration
{
    public readonly int $uid;
    public readonly string $providerIdentifier;
    public readonly string $title;
    public readonly string $apiKey;
    public readonly string $endpoint;
    public readonly string $model;
    public readonly string $costCurrency;
    public readonly float $totalCost;
    public readonly bool $disabled;
    public readonly bool $isDefault;
    public readonly string $beGroups;
    public readonly string $privacyLevel;
    public readonly bool $reroutingAllowed;
    public readonly bool $acceptsReroutedRequests;
    public readonly bool $autoModelSwitch;
    public readonly bool $gradingEnabled;
    public readonly int $judgeConfigurationUid;
    public readonly string $gradingRubric;
    public readonly string $systemPromptAddition;

    public function __construct(
        public readonly array $row,
    ) {
        $this->uid = (int)($row['uid'] ?? 0);
        $this->providerIdentifier = (string)($row['ai_provider'] ?? '');
        $this->title = (string)($row['title'] ?? '');
        $this->apiKey = (string)($row['api_key'] ?? '');
        $this->endpoint = (string)($row['endpoint'] ?? '') ?: self::legacyEndpointFrom($this->apiKey);
        $this->model = (string)($row['model'] ?? '');
        $this->costCurrency = (string)($row['cost_currency'] ?? 'USD');
        $this->totalCost = (float)($row['total_cost'] ?? 0);
        // A configuration with no model cannot serve a request, so it counts as
        // disabled everywhere resolution looks. The raw row keeps the editor's
        // own value, so the overview's enable toggle still reflects the column.
        $this->disabled = (bool)($row['disabled'] ?? false) || $this->model === '';
        $this->isDefault = (bool)($row['default'] ?? false);
        $this->beGroups = (string)($row['be_groups'] ?? '');
        $this->privacyLevel = (string)($row['privacy_level'] ?? 'standard');
        $this->reroutingAllowed = (bool)($row['rerouting_allowed'] ?? true);
        $this->acceptsReroutedRequests = (bool)($row['accepts_rerouted_requests'] ?? true);
        $this->autoModelSwitch = (bool)($row['auto_model_switch'] ?? true);
        $this->gradingEnabled = (bool)($row['grading_enabled'] ?? false);
        $this->judgeConfigurationUid = (int)($row['judge_configuration_uid'] ?? 0);
        $this->gradingRubric = (string)($row['grading_rubric'] ?? '');
        $this->systemPromptAddition = (string)($row['system_prompt_addition'] ?? '');
    }

    private static function legacyEndpointFrom(string $apiKey): string
    {
        return str_starts_with($apiKey, 'http://') || str_starts_with($apiKey, 'https://') ? $apiKey : '';
    }

    /**
     * Whether this configuration's credential belongs in the endpoint URL
     * rather than in a header, which is the case for a host that only does
     * basic auth. A bearer token is rejected by such a host, so the two are not
     * interchangeable.
     */
    public function expectsCredentialInUrl(): bool
    {
        return EndpointCredential::expectsCredential($this->endpoint);
    }

    /**
     * The endpoint to actually send to: the credential is put back into the URL
     * when that is where it belongs, and never stored that way.
     */
    public function getRequestEndpoint(): string
    {
        return $this->expectsCredentialInUrl()
            ? EndpointCredential::merge($this->endpoint, $this->apiKey)
            : $this->endpoint;
    }

    /**
     * Access provider-specific fields from the raw row.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->row[$key] ?? $default;
    }
}
