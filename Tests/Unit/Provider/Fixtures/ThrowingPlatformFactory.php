<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Provider\Fixtures;

/**
 * A bridge factory that rejects its credential and quotes it while doing so,
 * which is how a real bridge reports a malformed key. The parameter names
 * matter: the adapter reflects on them to decide what to pass.
 */
final class ThrowingPlatformFactory
{
    public static function createProvider(string $apiKey, ?string $endpoint = null): object
    {
        throw new \RuntimeException(sprintf('Invalid credential "%s" for host %s', $apiKey, (string)$endpoint));
    }
}
