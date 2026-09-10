<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\Refusing;

/**
 * A catalog that needs runtime context, like the ones self-hosted bridges
 * ship. Without any catalog at all the pass skips the package as not being a
 * real bridge, so a fixture has to have one.
 */
final class ModelCatalog
{
    public function __construct(private readonly string $endpoint)
    {
    }

    /**
     * A real dynamic catalog queries its endpoint here. The pass must never
     * reach this, because the required constructor argument is what marks the
     * catalog as dynamic, so throwing doubles as an assertion.
     */
    public function getModels(): array
    {
        throw new \LogicException('A dynamic catalog was instantiated for ' . $this->endpoint);
    }
}
