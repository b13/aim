<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Domain\Model;

/**
 * The single auto-detected tone-of-voice fragment for a site root page.
 *
 * $tone/$examples are the full, untruncated stored text; truncating for
 * display is a consumer UI decision.
 *
 * $isCliCalibrated distinguishes aim's own aim:calibrateVoice command from
 * a consumer's own equivalent, driven by the persisted auto_generated_source
 * column rather than the title, which an editor is free to rename without
 * affecting this.
 */
final class ToneOfVoiceInfo
{
    public function __construct(
        public readonly int $fragmentUid,
        public readonly string $title,
        public readonly bool $isCliCalibrated,
        public readonly string $tone,
        public readonly string $examples,
        public readonly string $editUrl,
    ) {}
}
