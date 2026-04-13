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

/**
 * Represents a tool/function call requested by the AI model.
 */
final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $arguments,
    ) {}

    public function getDecodedArguments(): array
    {
        try {
            return json_decode($this->arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }
}
