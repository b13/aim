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
 * How a footprint estimate was derived. Both are estimates; neither is a measurement.
 */
enum Method: string
{
    /** EcoLogits model: output tokens and model size, for hosted providers */
    case ModelEstimate = 'model_estimate';

    /** Inference time multiplied by a power draw the integrator calibrated, for local models */
    case DurationCalibrated = 'duration_calibrated';
}
