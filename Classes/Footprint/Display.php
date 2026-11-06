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

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The footprint of logged requests as the backend shows it: one request,
 * its list cell, and the totals of RequestLogRepository::getStatistics().
 * Reads the impact_details the Estimator writes.
 */
final class Display
{
    private const LLL = 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:';

    /**
     * Detail view of one log row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function entry(array $row): array
    {
        $details = self::details($row);
        if (!self::isEstimated($row)) {
            return [
                'available' => false,
                'reason' => (string)($details['unavailable'] ?? 'not_recorded'),
            ];
        }

        $facts = [];
        if (($row['impact_method'] ?? '') === Method::ModelEstimate->value) {
            $parameterDecimals = static fn(mixed $value): int => array_filter((array)$value, static fn(mixed $v): bool => fmod((float)$v, 1.0) !== 0.0) === [] ? 0 : 1;
            $facts['activeParameters'] = self::range($details['activeParameters'] ?? 0, $parameterDecimals($details['activeParameters'] ?? 0), ' B');
            if (($details['totalParameters'] ?? null) !== ($details['activeParameters'] ?? null)) {
                $facts['totalParameters'] = self::range($details['totalParameters'] ?? 0, $parameterDecimals($details['totalParameters'] ?? 0), ' B');
            }
            $facts['gpus'] = self::range($details['gpus'] ?? 0, 0);
            $facts['pue'] = self::range($details['pue'] ?? 0, 2);
            $facts['outputTokens'] = number_format((int)($details['outputTokens'] ?? 0), 0, '.', ',');
            $facts['generationSeconds'] = self::range($details['generationSeconds'] ?? 0, 2, ' s');
            if (is_array($details['embodiedCo2Grams'] ?? null)) {
                $facts['embodiedCo2Grams'] = Format::co2((float)($details['embodiedCo2Grams']['min'] ?? 0), (float)($details['embodiedCo2Grams']['max'] ?? 0));
            }
        } else {
            $facts['powerDrawWatts'] = self::range($details['powerDrawWatts'] ?? 0, 1, ' W');
            $facts['inferenceSeconds'] = self::range($details['inferenceSeconds'] ?? 0, 2, ' s');
            $facts['durationSource'] = $this->translate('footprint.durationSource.' . ($details['durationSource'] ?? ''));
        }
        $requested = $details['requestedModel'] ?? null;
        if (is_array($requested['co2Grams'] ?? null)) {
            $facts['requestedModel'] = Format::co2((float)($requested['co2Grams']['min'] ?? 0), (float)($requested['co2Grams']['max'] ?? 0));
            $routingChange = is_array($requested['avoidedCo2Grams'] ?? null) ? Format::routingChange(
                1,
                (float)($requested['avoidedCo2Grams']['min'] ?? 0),
                (float)($requested['avoidedCo2Grams']['max'] ?? 0),
            ) : null;
            if ($routingChange !== null) {
                $facts['routingChange'] = vsprintf($this->translate('footprint.routingChange.' . $routingChange['key']), $routingChange['values']);
            }
        }
        $gridSource = (string)($details['gridSource'] ?? '');
        $facts['gridIntensity'] = self::range($details['gridIntensity'] ?? 0, 1, ' gCO₂e/kWh') . ', '
            . ($this->translate('footprint.gridSource.' . $gridSource) ?: $gridSource);

        return [
            'available' => true,
            'method' => (string)($row['impact_method'] ?? ''),
            'co2' => Format::co2((float)$row['co2_g_min'], (float)$row['co2_g_max']),
            'energy' => Format::energy((float)$row['energy_wh_min'], (float)$row['energy_wh_max']),
            'facts' => $facts,
            'warnings' => array_values(array_filter((array)($details['warnings'] ?? []), 'is_string')),
            'dataVersion' => substr((string)($details['dataVersion'] ?? ''), 0, 7),
        ];
    }

    /**
     * List cell of one log row: the CO2e, or a dash with the reason as its title.
     *
     * @param array<string, mixed> $row
     * @return array{footprint: string, footprint_title: string}
     */
    public function cell(array $row): array
    {
        if (self::isEstimated($row)) {
            return [
                'footprint' => Format::co2((float)$row['co2_g_min'], (float)$row['co2_g_max']),
                'footprint_title' => Format::energy((float)($row['energy_wh_min'] ?? 0), (float)($row['energy_wh_max'] ?? 0)),
            ];
        }
        $reason = (string)(self::details($row)['unavailable'] ?? 'not_recorded');
        return [
            'footprint' => '–',
            'footprint_title' => $this->translate('footprint.noData') . ': ' . $this->translate('footprint.unavailable.' . $reason),
        ];
    }

    /**
     * Statistics card, as finished strings so the poll script only swaps text.
     *
     * @param array<string, mixed> $statistics
     * @return array{footprint: array<string, mixed>, footprint_display: string, footprint_coverage: string, footprint_routing: string}
     */
    public function statistics(array $statistics): array
    {
        $total = self::total($statistics);
        $routing = self::routing($statistics);
        return [
            'footprint' => $total,
            'footprint_display' => $total['hasData'] ? $total['co2'] : $this->translate('footprint.noData'),
            'footprint_coverage' => sprintf($this->translate('footprint.coverage'), $total['estimatedRequests'], $total['requests']),
            'footprint_routing' => $routing === null ? '' : vsprintf($this->translate('footprint.routing.' . $routing['key']), $routing['arguments']),
        ];
    }

    /**
     * @param array<string, mixed> $statistics
     * @return array{hasData: bool, co2: string, energy: string, pageViews: string, requests: string, estimatedRequests: string}
     */
    public static function total(array $statistics): array
    {
        return Format::summary([
            'requests' => (int)$statistics['total_requests'],
            'estimated_requests' => (int)$statistics['footprint_estimated_requests'],
            'co2_min' => (float)$statistics['footprint_co2_min'],
            'co2_max' => (float)$statistics['footprint_co2_max'],
            'energy_min' => (float)$statistics['footprint_energy_min'],
            'energy_max' => (float)$statistics['footprint_energy_max'],
        ]);
    }

    /**
     * What smart routing changed, as a footprint.routing.* label key and its arguments. Null when nothing was routed.
     *
     * @param array<string, mixed> $statistics
     * @return array{key: string, arguments: list<string>}|null
     */
    public static function routing(array $statistics): ?array
    {
        $estimatedRoutings = (int)$statistics['footprint_routing_estimated_requests'];
        $change = Format::routingChange($estimatedRoutings, (float)$statistics['footprint_avoided_min'], (float)$statistics['footprint_avoided_max']);
        return $change === null ? null : [
            'key' => $change['key'],
            'arguments' => [...$change['values'], number_format($estimatedRoutings, 0, '.', ',')],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isEstimated(array $row): bool
    {
        return ($row['co2_g_min'] ?? null) !== null && ($row['co2_g_max'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function details(array $row): array
    {
        $details = json_decode((string)($row['impact_details'] ?? ''), true);
        return is_array($details) ? $details : [];
    }

    private static function range(mixed $value, int $decimals, string $suffix = ''): string
    {
        $min = number_format((float)(is_array($value) ? ($value['min'] ?? 0) : $value), $decimals, '.', ',');
        $max = number_format((float)(is_array($value) ? ($value['max'] ?? 0) : $value), $decimals, '.', ',');
        return ($min === $max ? $max : $min . '–' . $max) . $suffix;
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()->sL(self::LLL . $key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
