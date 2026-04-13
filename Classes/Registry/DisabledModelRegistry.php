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

use TYPO3\CMS\Core\Registry;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Tracks which provider models are disabled by the admin.
 *
 * Persisted in sys_registry as a map: {"providerIdentifier": ["model1", "model2"]}.
 * Disabled models are enforced at three levels:
 * - TCA: excluded from the model dropdown in provider configurations
 * - Provider resolution: skipped when resolving providers and building fallback chains
 * - Middleware: blocked by CapabilityValidationMiddleware as a safety net
 */
#[Autoconfigure(public: true)]
class DisabledModelRegistry
{
    private const REGISTRY_NAMESPACE = 'aim';
    private const REGISTRY_KEY = 'disabledModels';

    public function __construct(
        private readonly Registry $registry,
    ) {}

    /**
     * Toggle a model's disabled state. Returns true if now disabled, false if re-enabled.
     */
    public function toggle(string $providerIdentifier, string $model): bool
    {
        $all = $this->getAll();
        $disabled = $all[$providerIdentifier] ?? [];

        if (in_array($model, $disabled, true)) {
            $all[$providerIdentifier] = array_values(array_diff($disabled, [$model]));
            if ($all[$providerIdentifier] === []) {
                unset($all[$providerIdentifier]);
            }
            $this->save($all);
            return false;
        }

        $all[$providerIdentifier][] = $model;
        $this->save($all);
        return true;
    }

    public function isDisabled(string $providerIdentifier, string $model): bool
    {
        $all = $this->getAll();
        return in_array($model, $all[$providerIdentifier] ?? [], true);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getAll(): array
    {
        return $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, []);
    }

    private function save(array $data): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $data);
    }
}
