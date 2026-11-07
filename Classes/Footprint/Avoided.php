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
 * CO2e a smart-routing downgrade avoided, as a range. Negative where routing added CO2e.
 */
final class Avoided
{
    public function __construct(
        public readonly Estimate $requested,
        public readonly float $co2GramsMin,
        public readonly float $co2GramsMax,
    ) {}
}
