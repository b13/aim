<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Utility;

/**
 * Strips markdown code fences and locates the first balanced JSON object in
 * a string. Tolerates the common failure mode of LLMs wrapping requested
 * JSON output in ```json ... ``` fences or a sentence of surrounding prose,
 * even when a strict response-format hint was also sent.
 *
 * Shared by every internal AiM call site that asks a model to reply with a
 * single JSON object (GradingService's judge call, VoiceCalibrationService's
 * tone analysis).
 */
final class JsonExtractor
{
    public static function extractJsonObject(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*\n?|\n?```$/m', '', $raw) ?? $raw;
            $raw = trim($raw);
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return substr($raw, $start, $end - $start + 1);
    }
}
