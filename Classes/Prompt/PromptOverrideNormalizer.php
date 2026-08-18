<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Prompt;

/**
 * Normalizes the public API's flexible `string|array` systemPromptOverride
 * parameter (Ai::*(), AiRequestBuilder::systemPromptOverride()) into the
 * `list<string>` the request DTOs carry internally.
 */
final class PromptOverrideNormalizer
{
    /**
     * @return list<string>
     */
    public static function normalize(string|array $override): array
    {
        $list = is_array($override) ? $override : [$override];
        return array_values(array_filter(
            // is_scalar() guards against a non-scalar element (e.g. a
            // caller-side bug passing a nested array) silently becoming the
            // literal text "Array" via PHP's string cast; it is dropped instead,
            // same as every other malformed-input case in this codebase.
            array_map(static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '', $list),
            static fn(string $value): bool => $value !== '',
        ));
    }
}
