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
 * Why no footprint could be estimated for a request. Shown instead of a value, never as zero.
 */
enum Unavailable: string
{
    case RequestFailed = 'request_failed';
    case UnsupportedRequestType = 'unsupported_request_type';
    case NoOutputTokens = 'no_output_tokens';
    case NoDataSource = 'no_data_source';
    case UnknownModel = 'unknown_model';
    case StreamedLocalRequest = 'streamed_local_request';
    case NoDuration = 'no_duration';
    case EstimationFailed = 'estimation_failed';
}
