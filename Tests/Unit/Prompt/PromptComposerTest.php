<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Prompt;

use B13\Aim\Prompt\PromptComposer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PromptComposerTest extends TestCase
{
    #[Test]
    public function joinsNonEmptyPartsWithABlankLine(): void
    {
        self::assertSame(
            "First.\n\nSecond.\n\nThird.",
            PromptComposer::compose(['First.', 'Second.', 'Third.']),
        );
    }

    #[Test]
    public function trimsAndDropsBlankParts(): void
    {
        self::assertSame(
            'Only this.',
            PromptComposer::compose(['  ', '', 'Only this.', '   ']),
        );
    }

    #[Test]
    public function returnsEmptyStringWhenEverythingIsBlank(): void
    {
        self::assertSame('', PromptComposer::compose(['', '   ', '']));
    }

    #[Test]
    public function dropsExactDuplicatesKeepingTheFirstOccurrence(): void
    {
        self::assertSame(
            ['A', 'B'],
            PromptComposer::dedupe(['A', 'B', 'A', ' A ', 'B']),
        );
    }

    #[Test]
    public function dedupeAndComposeAgreeOnOrderAndContent(): void
    {
        self::assertSame(
            "A\n\nB",
            PromptComposer::compose(['A', 'B', 'A']),
        );
    }

    #[Test]
    public function isRedundantIsTrueForABlankPart(): void
    {
        self::assertTrue(PromptComposer::isRedundant('   ', ['Something else.']));
    }

    #[Test]
    public function isRedundantIsTrueForAnExactDuplicateOfAnExistingPart(): void
    {
        self::assertTrue(PromptComposer::isRedundant('Already there.', ['Already there.']));
    }

    #[Test]
    public function isRedundantTrimsBeforeComparing(): void
    {
        self::assertTrue(PromptComposer::isRedundant('  Already there.  ', ['Already there.']));
    }

    #[Test]
    public function isRedundantIsFalseForGenuinelyNewContent(): void
    {
        self::assertFalse(PromptComposer::isRedundant('Brand new.', ['Something else.']));
    }
}
