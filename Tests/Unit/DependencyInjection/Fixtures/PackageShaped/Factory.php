<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\PackageShaped;

/**
 * A bridge that answers with something shaped like a package name, which would
 * only move the problem into the service id and the database column.
 */
final class Factory
{
    public static function createProvider(string $apiKey): object
    {
        return new class() {
            public function getName(): string
            {
                return 'acme/ai-cool';
            }
        };
    }
}
