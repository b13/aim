<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Governance;

/**
 * Result of a budget check — whether the user is allowed to proceed.
 */
final class BudgetCheckResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {}

    public static function allowed(): self
    {
        return new self(true, '');
    }

    public static function denied(string $reason): self
    {
        return new self(false, $reason);
    }
}
