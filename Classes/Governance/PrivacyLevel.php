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
 * Privacy level for AI request logging.
 *
 * Controls how much data is stored in the request log.
 * The effective level is the strictest of the provider config
 * and the user's TSconfig setting.
 */
enum PrivacyLevel: string
{
    /** Full logging — prompt, response, tokens, cost, everything. */
    case Standard = 'standard';

    /** Metadata only — tokens, cost, model, duration, but no prompt/response content. */
    case Reduced = 'reduced';

    /** No logging at all — request is not recorded. */
    case None = 'none';

    /**
     * Return the stricter of two privacy levels.
     * none > reduced > standard
     */
    public function strictest(self $other): self
    {
        return self::fromStrictness(max($this->strictness(), $other->strictness()));
    }

    private function strictness(): int
    {
        return match ($this) {
            self::Standard => 0,
            self::Reduced => 1,
            self::None => 2,
        };
    }

    private static function fromStrictness(int $level): self
    {
        return match ($level) {
            2 => self::None,
            1 => self::Reduced,
            default => self::Standard,
        };
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Standard;
    }
}
