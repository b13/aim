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
 * Shared trim/dedupe/join logic for composing a final prompt out of several
 * parts (caller instruction, tone-of-voice fragments, provider addendum,
 * ...). Used by both TonePromptCompositionMiddleware and
 * ProviderAddendumMiddleware. Split into two middlewares running at
 * different pipeline priorities, but sharing this one composition rule
 * so the resulting prompt is assembled consistently regardless of which
 * phase contributed which part.
 */
final class PromptComposer
{
    /**
     * Trims each part, drops blanks, and drops exact duplicates (first
     * occurrence wins). So the same instruction appearing in two different
     * sources (e.g. a DB fragment and a code-registered one with identical
     * wording) doesn't get sent to the provider twice. Granularity note: a
     * duplicate within an already-merged unit (e.g. PagePromptResolver's
     * merged DB+TSconfig tone) isn't caught here. Only whole-part
     * duplicates across what's passed in.
     *
     * @param list<string> $parts
     * @return list<string>
     */
    public static function dedupe(array $parts): array
    {
        $seen = [];
        $deduped = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '' || isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            $deduped[] = $trimmed;
        }
        return $deduped;
    }

    /**
     * @param list<string> $parts
     */
    public static function compose(array $parts): string
    {
        return implode("\n\n", self::dedupe($parts));
    }

    /**
     * Whether $part is blank, or an exact duplicate of something already in
     * $existingParts, and so should be skipped rather than added again.
     * Shared by ProviderAddendumMiddleware (suppressing an addendum that
     * exactly duplicates what TonePromptCompositionMiddleware already
     * composed) and PromptPreviewService (mirroring that same suppression
     * for the read-only preview). Both need the identical rule, so a
     * future change to it can't silently drift between preview and
     * production by being hand-copied in only one place.
     *
     * @param list<string> $existingParts
     */
    public static function isRedundant(string $part, array $existingParts): bool
    {
        $trimmed = trim($part);
        return $trimmed === '' || in_array($trimmed, $existingParts, true);
    }
}
