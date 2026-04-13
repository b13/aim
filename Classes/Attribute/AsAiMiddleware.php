<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Attribute;

/**
 * Marks a class as an AI middleware for automatic DI registration.
 *
 * Middleware is sorted by priority: higher values run first (outermost),
 * lower values run closer to the actual provider call.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AsAiMiddleware
{
    public const TAG_NAME = 'b13.aim.middleware';

    public function __construct(
        public int $priority = 0,
    ) {}
}
