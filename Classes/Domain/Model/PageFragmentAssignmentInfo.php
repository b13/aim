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
 * One tx_aim_page_prompt_fragment assignment on a specific page.
 * $scopeLabels is already resolved to human-readable capability labels;
 * $editUrl already points at the assignment's own edit form, so a
 * consumer never needs to know the underlying table names.
 */
final class PageFragmentAssignmentInfo
{
    /**
     * @param list<string> $scopeLabels
     */
    public function __construct(
        public readonly int $assignmentUid,
        public readonly string $title,
        public readonly array $scopeLabels,
        public readonly bool $inheritToSubpages,
        public readonly string $editUrl,
    ) {}
}
