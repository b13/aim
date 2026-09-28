<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\ThrowingCatalog;

/**
 * A catalog that looks buildable and then is not. Whatever the reason, it says
 * nothing about whether the package is a bridge.
 */
final class ModelCatalog
{
    public function __construct()
    {
        throw new \RuntimeException('This catalog refuses to be built at compile time.', 1790346979);
    }

    public function getModels(): array
    {
        return [];
    }
}
