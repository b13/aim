<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request\Message;

/**
 * Base class for typed AI conversation messages.
 *
 * Subclasses enforce a specific role while keeping a uniform API
 * for provider implementations to work with.
 */
abstract class AbstractMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string|array $content,
    ) {}

    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }
}
