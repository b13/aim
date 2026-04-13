<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Routing;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Loads and merges language-specific complexity signals from all extensions.
 *
 * Each extension can provide:
 *   Configuration/SmartRouting/ComplexitySignals.php
 *
 * The file returns an array keyed by ISO 639-1 language code (en, de, fr, ...),
 * each containing 'complex', 'simple', 'multiPart' arrays.
 *
 * Extensions can:
 *   - Add a new language by providing a new key
 *   - Extend an existing language (signals are merged, not replaced)
 *   - Override at runtime via $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['complexitySignals']
 */
#[Autoconfigure(public: true)]
class ComplexitySignalRegistry
{
    /** @var array{complex: list<string>, simple: list<string>, multiPart: list<string>}|null */
    private ?array $flattened = null;

    public function __construct(
        private readonly PackageManager $packageManager,
    ) {}

    /**
     * Get all signals flattened across all languages.
     *
     * For matching, we don't need to know the language — we check all
     * signals against the prompt. The language structure is only for
     * authoring and extensibility.
     *
     * @return array{complex: list<string>, simple: list<string>, multiPart: list<string>}
     */
    public function getSignals(): array
    {
        if ($this->flattened !== null) {
            return $this->flattened;
        }

        $byLanguage = $this->loadByLanguage();

        // Flatten all languages into a single signal set
        $flat = ['complex' => [], 'simple' => [], 'multiPart' => []];
        foreach ($byLanguage as $signals) {
            foreach (['complex', 'simple', 'multiPart'] as $key) {
                if (isset($signals[$key]) && is_array($signals[$key])) {
                    array_push($flat[$key], ...array_values($signals[$key]));
                }
            }
        }

        // Deduplicate and lowercase
        foreach ($flat as $key => $values) {
            $flat[$key] = array_values(array_unique(array_map('mb_strtolower', $values)));
        }

        $this->flattened = $flat;
        return $this->flattened;
    }

    /**
     * Load all signals grouped by language code.
     *
     * Merges from all extensions + $GLOBALS runtime overrides.
     *
     * @return array<string, array{complex?: list<string>, simple?: list<string>, multiPart?: list<string>}>
     */
    private function loadByLanguage(): array
    {
        $merged = [];

        // Scan all active extensions
        foreach ($this->packageManager->getActivePackages() as $package) {
            $file = $package->getPackagePath() . 'Configuration/SmartRouting/ComplexitySignals.php';
            if (!is_file($file)) {
                continue;
            }
            $signals = require $file;
            if (is_array($signals)) {
                $this->mergeSignals($merged, $signals);
            }
        }

        // Runtime overrides from $GLOBALS (same language-keyed structure)
        $globals = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['complexitySignals'] ?? [];
        if (is_array($globals)) {
            $this->mergeSignals($merged, $globals);
        }

        return $merged;
    }

    /**
     * Merge a language-keyed signal array into the accumulated result.
     */
    private function mergeSignals(array &$merged, array $signals): void
    {
        foreach ($signals as $lang => $langSignals) {
            if (!is_string($lang) || !is_array($langSignals)) {
                continue;
            }
            foreach (['complex', 'simple', 'multiPart'] as $key) {
                if (isset($langSignals[$key]) && is_array($langSignals[$key])) {
                    $merged[$lang][$key] = array_merge(
                        $merged[$lang][$key] ?? [],
                        $langSignals[$key],
                    );
                }
            }
        }
    }
}
