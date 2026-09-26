<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\Catalogless;

/**
 * A package of the bridge Composer type carrying a Factory but no catalog
 * beside it. Symfony AI's Provider cannot be built without a catalog, so this
 * is not a bridge whatever its package type claims.
 */
final class Factory
{
    public static function createProvider(string $apiKey): object
    {
        return new \stdClass();
    }
}
