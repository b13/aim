<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\ThirdParty;

/**
 * A bridge from a vendor other than symfony, shaped like the real ones: a
 * static createProvider() whose only required argument is the credential.
 */
final class Factory
{
    public static function createProvider(string $apiKey, ?string $endpoint = null): object
    {
        return new class() {
            public function getName(): string
            {
                return 't3ppy';
            }
        };
    }
}
