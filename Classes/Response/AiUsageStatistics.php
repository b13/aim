<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Response;

final class AiUsageStatistics
{
    public function __construct(
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly float $cost = 0.0,
        public readonly int $cachedTokens = 0,
        public readonly int $reasoningTokens = 0,
        public readonly string $modelUsed = '',
        public readonly string $systemFingerprint = '',
        public readonly array $rawUsage = [],
    ) {}

    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
