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
 * Estimated usage-phase energy and CO2e of one request, as a min/max range.
 */
final class Estimate
{
    /**
     * @param array<string, mixed> $details Inputs the estimate was computed from, persisted for traceability
     */
    public function __construct(
        public readonly Method $method,
        public readonly float $energyWhMin,
        public readonly float $energyWhMax,
        public readonly float $co2GramsMin,
        public readonly float $co2GramsMax,
        public readonly array $details,
    ) {}
}
