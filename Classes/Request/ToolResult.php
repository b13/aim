<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request;

/**
 * Result of a tool invocation, fed back to the model for continued processing.
 */
final class ToolResult
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $name,
        public readonly string $output,
    ) {}
}
