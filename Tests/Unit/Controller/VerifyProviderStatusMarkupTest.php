<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Status column is server-rendered as an .aim-chip with a data-tone, and
 * verify-provider.js replaces that cell after a check. It used to write
 * Bootstrap badge markup instead, so the row that had just been verified was
 * styled unlike every other row in the same column until the next page load,
 * and the ".badge" its in-progress state looked for did not exist until the
 * result had been written once, which made the first click behave differently
 * from every following one.
 *
 * Inspected as source, since this suite runs no browser; same approach as
 * Tests/Unit/Controller/RequestLogPollParametersTest.php. Cross-checked
 * against the template that renders the cell and the stylesheet that gives a
 * chip its colour, because agreeing with those two is the whole contract.
 */
final class VerifyProviderStatusMarkupTest extends TestCase
{
    private const JS = __DIR__ . '/../../../Resources/Public/JavaScript/verify-provider.js';
    private const TEMPLATE = __DIR__ . '/../../../Resources/Private/Templates/Aim/Overview.html';
    private const STYLESHEET = __DIR__ . '/../../../Resources/Public/Css/base.css';

    #[Test]
    public function theModuleRendersTheSameChipMarkupTheTemplateDoes(): void
    {
        $template = $this->read(self::TEMPLATE);
        self::assertMatchesRegularExpression(
            '/data-verify-status=.*?\n(?:.*?\n){0,20}?.*?class="aim-chip"/s',
            $template,
            'Sanity check on the template this test reads: the status cell is expected to render aim-chip.',
        );

        $source = $this->read(self::JS);
        self::assertStringContainsString("'aim-chip'", $source, 'The module does not build a chip at all.');
        self::assertDoesNotMatchRegularExpression(
            '/[\x27"`][^\x27"`]*\bbadge\b[^\x27"`]*[\x27"`]/',
            $this->withoutComments($source),
            'The module still emits Bootstrap badge markup, which the Status column never uses.',
        );
    }

    /**
     * A chip takes its colour, border and background from its tone alone, so a
     * tone the stylesheet does not define renders as plain bold text: the same
     * silent failure the .aim-chip rules were unscoped for once before.
     */
    #[Test]
    public function everyToneTheModuleUsesIsDefinedInTheStylesheet(): void
    {
        preg_match_all('/\.aim-chip\[data-tone="(\w+)"\]/', $this->read(self::STYLESHEET), $defined);
        $definedTones = $defined[1];
        self::assertContains('good', $definedTones, 'Sanity check on the stylesheet this test reads.');

        $usedTones = $this->tonesUsedByTheModule();
        self::assertNotSame([], $usedTones, 'No tone mapping was found in the module.');

        foreach ($usedTones as $state => $tone) {
            self::assertContains(
                $tone,
                $definedTones,
                sprintf('The module renders the "%s" state with an undefined tone "%s".', $state, $tone),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function tonesUsedByTheModule(): array
    {
        $source = $this->read(self::JS);
        preg_match('/const TONES = \{(.+?)\};/s', $source, $block);
        preg_match_all("/(\w+): '(\w+)'/", $block[1] ?? '', $matches, PREG_SET_ORDER);

        $tones = [];
        foreach ($matches as $match) {
            $tones[$match[1]] = $match[2];
        }

        return $tones;
    }

    /**
     * Docblocks in this module name the markup it deliberately no longer
     * writes, which is worth keeping and must not fail the assertion above.
     */
    private function withoutComments(string $source): string
    {
        return (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
    }

    private function read(string $path): string
    {
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
