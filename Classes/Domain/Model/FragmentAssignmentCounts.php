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
 * Install-wide tx_aim_page_prompt_fragment assignment counts.
 * Counts assignments (page usages), not library fragments.
 */
final class FragmentAssignmentCounts
{
    public function __construct(
        public readonly int $total,
        public readonly int $inherited,
    ) {}
}
