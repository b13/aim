<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\EmptyCatalog;

/**
 * Shaped like symfony/ai-open-responses-platform: the endpoint is the required
 * argument, the credential is optional, and the catalog handed to the provider
 * at runtime accepts any model name.
 */
final class Factory
{
    public static function createProvider(string $baseUrl, ?string $apiKey = null): object
    {
        return new class() {
            public function getName(): string
            {
                return 'openresponses';
            }
        };
    }
}
