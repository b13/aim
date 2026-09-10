<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Domain\Model;

use B13\Aim\Domain\Model\ProviderConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The model field is no longer required, so a configuration can be saved before
 * its model list is reachable. Until one is picked it cannot serve a request,
 * so it counts as disabled.
 */
final class ModellessConfigurationTest extends TestCase
{
    #[Test]
    public function aConfigurationWithoutAModelCountsAsDisabled(): void
    {
        $configuration = new ProviderConfiguration(['model' => '', 'disabled' => 0]);

        self::assertTrue($configuration->disabled);
    }

    #[Test]
    public function aConfigurationWithAModelIsNotDisabledByThat(): void
    {
        $configuration = new ProviderConfiguration(['model' => 'llama3.2:latest', 'disabled' => 0]);

        self::assertFalse($configuration->disabled);
    }

    #[Test]
    public function anExplicitlyDisabledConfigurationStaysDisabled(): void
    {
        $configuration = new ProviderConfiguration(['model' => 'llama3.2:latest', 'disabled' => 1]);

        self::assertTrue($configuration->disabled);
    }

    /**
     * The raw row still carries what the editor actually set, so the enable and
     * disable toggle in the overview keeps reflecting the real column.
     */
    #[Test]
    public function theRawRowIsNotRewritten(): void
    {
        $configuration = new ProviderConfiguration(['model' => '', 'disabled' => 0]);

        self::assertSame(0, $configuration->row['disabled']);
    }
}
