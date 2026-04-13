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
    public readonly string $model;
    public readonly string $costCurrency;
    public readonly float $totalCost;
    public readonly bool $disabled;
    public readonly bool $isDefault;
    public readonly string $beGroups;
    public readonly string $privacyLevel;
    public readonly bool $reroutingAllowed;
    public readonly bool $autoModelSwitch;

    public function __construct(
        public readonly array $row,
    ) {
        $this->uid = (int)($row['uid'] ?? 0);
        $this->providerIdentifier = (string)($row['ai_provider'] ?? '');
        $this->title = (string)($row['title'] ?? '');
        $this->apiKey = (string)($row['api_key'] ?? '');
        $this->model = (string)($row['model'] ?? '');
        $this->costCurrency = (string)($row['cost_currency'] ?? 'USD');
        $this->totalCost = (float)($row['total_cost'] ?? 0);
        $this->disabled = (bool)($row['disabled'] ?? false);
        $this->isDefault = (bool)($row['default'] ?? false);
        $this->beGroups = (string)($row['be_groups'] ?? '');
        $this->privacyLevel = (string)($row['privacy_level'] ?? 'standard');
        $this->reroutingAllowed = (bool)($row['rerouting_allowed'] ?? true);
        $this->autoModelSwitch = (bool)($row['auto_model_switch'] ?? true);
    }

    /**
     * Access provider-specific fields from the raw row.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->row[$key] ?? $default;
    }
}
