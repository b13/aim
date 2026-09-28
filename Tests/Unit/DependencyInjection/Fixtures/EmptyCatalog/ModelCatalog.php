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
 * A catalog that can be built without runtime context but knows no models of
 * its own, the shape symfony/ai-open-responses-platform ships: models are
 * registered explicitly by whoever configures the bridge.
 */
final class ModelCatalog
{
    /**
     * @param array<string, array{class: class-string, capabilities: list<object>}> $models
     */
    public function __construct(private readonly array $models = [])
    {
    }

    /**
     * @return array<string, array{class: class-string, capabilities: list<object>}>
     */
    public function getModels(): array
    {
        return $this->models;
    }
}
