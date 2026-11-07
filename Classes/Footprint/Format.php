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
 * Human-readable footprint values. A value above zero never renders as "0".
 */
final class Format
{
    /** Average page view, Sustainable Web Design Model v4 */
    public const PAGE_VIEW_GRAMS = 0.36;

    private const MASS_UNITS = [[1000.0, 'kg'], [1.0, 'g'], [0.001, 'mg'], [0.000001, 'µg']];
    private const ENERGY_UNITS = [[1000.0, 'kWh'], [1.0, 'Wh'], [0.001, 'mWh'], [0.000001, 'µWh']];

    /**
     * Display values for an aggregate of logged requests.
     *
     * @param array{requests: int, estimated_requests: int, co2_min: float, co2_max: float, energy_min: float, energy_max: float} $aggregate
     * @return array{hasData: bool, co2: string, energy: string, pageViews: string, requests: string, estimatedRequests: string}
     */
    public static function summary(array $aggregate): array
    {
        $hasData = $aggregate['estimated_requests'] > 0;
        return [
            'hasData' => $hasData,
            'co2' => $hasData ? self::co2($aggregate['co2_min'], $aggregate['co2_max']) : '',
            'energy' => $hasData ? self::energy($aggregate['energy_min'], $aggregate['energy_max']) : '',
            'pageViews' => $hasData ? self::pageViews($aggregate['co2_min'], $aggregate['co2_max']) : '',
            'requests' => number_format($aggregate['requests'], 0, '.', ','),
            'estimatedRequests' => number_format($aggregate['estimated_requests'], 0, '.', ','),
        ];
    }

    /**
     * What smart routing changed: CO2e "avoided" or "added", or "mixed" when
     * the range spans zero, with the added and the avoided end. Null when nothing was routed.
     *
     * @return array{key: 'avoided'|'added'|'mixed', values: list<string>}|null
     */
    public static function routingChange(int $routedRequests, float $low, float $high): ?array
    {
        [$low, $high] = [min($low, $high), max($low, $high)];
        if ($routedRequests === 0 || ($low === 0.0 && $high === 0.0)) {
            return null;
        }
        if ($low >= 0.0) {
            return ['key' => 'avoided', 'values' => [self::co2($low, $high)]];
        }
        if ($high <= 0.0) {
            return ['key' => 'added', 'values' => [self::co2(-$high, -$low)]];
        }
        return ['key' => 'mixed', 'values' => [self::co2(-$low, -$low), self::co2($high, $high)]];
    }

    public static function co2(float $min, float $max): string
    {
        return self::range($min, $max, self::MASS_UNITS) . ' CO₂e';
    }

    public static function energy(float $min, float $max): string
    {
        return self::range($min, $max, self::ENERGY_UNITS);
    }

    public static function pageViews(float $co2Min, float $co2Max): string
    {
        $min = $co2Min / self::PAGE_VIEW_GRAMS;
        $max = $co2Max / self::PAGE_VIEW_GRAMS;
        $decimals = self::decimals($max);
        return self::join(self::number($min, $decimals), self::number($max, $decimals));
    }

    /**
     * @param list<array{0: float, 1: string}> $units
     */
    private static function range(float $min, float $max, array $units): string
    {
        $max = max($min, $max);
        // Rounded first, so 0.99996 g becomes 1.0 g rather than 1,000 mg.
        // The largest unit that keeps the value at 0.1 or more: 0.85 mg, not 849 µg.
        $rounded = $max > 0.0 ? round($max, self::decimals($max)) : $max;
        [$factor, $unit] = end($units);
        foreach ($units as [$candidateFactor, $candidateUnit]) {
            if ($rounded >= $candidateFactor / 10) {
                [$factor, $unit] = [$candidateFactor, $candidateUnit];
                break;
            }
        }
        $decimals = self::decimals($rounded / $factor);
        return self::join(self::number($min / $factor, $decimals), self::number($max / $factor, $decimals)) . ' ' . $unit;
    }

    /**
     * Two significant digits for the larger value of a range; more would suggest a precision the estimates don't have.
     */
    private static function decimals(float $value): int
    {
        if ($value <= 0.0) {
            return 0;
        }
        return max(0, 1 - (int)floor(log10($value)));
    }

    private static function number(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, '.', ',');
        if ($value > 0.0 && (float)str_replace(',', '', $formatted) === 0.0) {
            return '< ' . number_format(10 ** -$decimals, $decimals, '.', ',');
        }
        return $formatted;
    }

    private static function join(string $min, string $max): string
    {
        return $min === $max ? $max : $min . '–' . $max;
    }
}
