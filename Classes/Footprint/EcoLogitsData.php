<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Footprint;

/**
 * Read access to the EcoLogits model, provider and electricity mix data
 * shipped in Resources/Private/Data/EcoLogits (MPL-2.0).
 */
class EcoLogitsData
{
    private const DATA_FILE = __DIR__ . '/../../Resources/Private/Data/EcoLogits/data.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, array<string, mixed>>|null keyed by "provider|name", aliases included */
    private ?array $models = null;

    public function __construct(
        private readonly string $dataFile = self::DATA_FILE,
    ) {}

    /**
     * Exact (provider, name) lookup, aliases included, as EcoLogits does it.
     *
     * @return array<string, mixed>|null
     */
    public function findModel(string $provider, string $modelName): ?array
    {
        return $this->getModels()[$provider . '|' . $modelName] ?? null;
    }

    /**
     * @return array{location: string, pue: float|array{min: float, max: float}}|null
     */
    public function getProvider(string $provider): ?array
    {
        return $this->load()['providers'][$provider] ?? null;
    }

    /**
     * Grid carbon intensity in gCO2e/kWh for an ISO 3166-1 alpha-3 zone, WOR for the world average.
     */
    public function getGridIntensity(string $zone): ?float
    {
        $value = $this->load()['electricityMixes'][$zone] ?? null;
        return $value === null ? null : (float)$value;
    }

    public function getVersion(): string
    {
        return (string)($this->load()['_source']['commit'] ?? '');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getModels(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }
        $models = [];
        foreach ($this->load()['models'] ?? [] as $model) {
            $models[$model['provider'] . '|' . $model['name']] = $model;
        }
        foreach ($this->load()['aliases'] ?? [] as $alias) {
            $target = $models[$alias['provider'] . '|' . $alias['alias']] ?? null;
            if ($target !== null) {
                $models[$alias['provider'] . '|' . $alias['name']] = $target;
            }
        }
        return $this->models = $models;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->data === null) {
            $contents = @file_get_contents($this->dataFile);
            $this->data = $contents !== false ? (json_decode($contents, true) ?: []) : [];
        }
        return $this->data;
    }
}
