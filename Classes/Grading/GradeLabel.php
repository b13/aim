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
 * Qualitative grade assigned to an AI response by the LLM judge.
 *
 * Stored in tx_aim_request_log.grade_label. The judge is asked to return
 * one of these values directly; when it returns something else, the label
 * is derived from the numeric score via fromScore().
 */
enum GradeLabel: string
{
    case Poor = 'poor';
    case Fair = 'fair';
    case Good = 'good';
    case Excellent = 'excellent';

    /**
     * Derive a label from a 0.0–1.0 score. Used as a fallback when the
     * judge returns a score but no recognizable label.
     */
    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 0.85 => self::Excellent,
            $score >= 0.65 => self::Good,
            $score >= 0.40 => self::Fair,
            default => self::Poor,
        };
    }
}
