<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\DeclaredName;

/**
 * The shape every measured bridge has: the canonical name is the default of a
 * `$name` parameter, and the credential is validated eagerly so the factory
 * cannot be built at all. Reflection has to answer here, or nothing does.
 */
final class Factory
{
    public static function createProvider(string $apiKey, string $name = 'acmecloud'): object
    {
        throw new \InvalidArgumentException('The API key is empty.');
    }
}
