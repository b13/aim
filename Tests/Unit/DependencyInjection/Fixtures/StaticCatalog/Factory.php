<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\StaticCatalog;

/**
 * Shaped like a third-party bridge factory: the credential
 * is the required argument, and as the package name cannot be read as an
 * identifier, the bridge's declared name is used.
 */
final class Factory
{
    public static function createProvider(string $apiKey, string $name = 'acme'): object
    {
        return new class() {
            public function getName(): string
            {
                return 'acme';
            }
        };
    }
}
