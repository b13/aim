<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Grading;

/**
 * Lifecycle state of grading for a single tx_aim_request_log row.
 *
 *  - None:    the request was never eligible for grading (default).
 *  - Pending: eligible and queued; awaiting the judge call.
 *  - Done:    graded successfully; grade_score/label/reason are populated.
 *  - Failed:  grading was attempted but errored; see grade_error.
 */
enum GradeStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
