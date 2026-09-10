<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which fields a provider configuration insists on. The model field must stay
 * optional, or a provider whose model list needs a credential cannot be saved
 * at all: nothing can be enumerated before the record exists.
 */
final class TcaConfigurationRequiredFieldsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fields(): array
    {
        return [
            'provider is required' => ['ai_provider', true],
            'title is required' => ['title', true],
            'model is optional' => ['model', false],
        ];
    }

    #[Test]
    #[DataProvider('fields')]
    public function theFieldRequirementIsAsIntended(string $field, bool $expected): void
    {
        $tca = require dirname(__DIR__, 3) . '/Configuration/TCA/tx_aim_configuration.php';

        self::assertArrayHasKey($field, $tca['columns']);
        self::assertSame(
            $expected,
            (bool)($tca['columns'][$field]['config']['required'] ?? false),
            sprintf('Field "%s" has the wrong required flag.', $field),
        );
    }

    /**
     * The two select fields differ only in their itemsProcFunc, which is how an
     * edit meant for one can silently land on the other.
     */
    #[Test]
    public function theTwoSelectFieldsKeepTheirOwnItemsProcFunc(): void
    {
        $tca = require dirname(__DIR__, 3) . '/Configuration/TCA/tx_aim_configuration.php';

        self::assertStringEndsWith('->getAiProviders', $tca['columns']['ai_provider']['config']['itemsProcFunc']);
        self::assertStringEndsWith('->getAiProviderModels', $tca['columns']['model']['config']['itemsProcFunc']);
    }
}
