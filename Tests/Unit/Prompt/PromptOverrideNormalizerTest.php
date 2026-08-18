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

use B13\Aim\Prompt\PromptOverrideNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PromptOverrideNormalizerTest extends TestCase
{
    #[Test]
    public function wrapsAPlainStringIntoASingleElementList(): void
    {
        self::assertSame(['Hello.'], PromptOverrideNormalizer::normalize('Hello.'));
    }

    #[Test]
    public function trimsEachElement(): void
    {
        self::assertSame(['Hello.'], PromptOverrideNormalizer::normalize('  Hello.  '));
        self::assertSame(['A', 'B'], PromptOverrideNormalizer::normalize([' A ', ' B ']));
    }

    #[Test]
    public function dropsBlankElements(): void
    {
        self::assertSame([], PromptOverrideNormalizer::normalize(''));
        self::assertSame([], PromptOverrideNormalizer::normalize('   '));
        self::assertSame(['A'], PromptOverrideNormalizer::normalize(['A', '', '   ']));
    }

    #[Test]
    public function reindexesToAListAfterFiltering(): void
    {
        self::assertSame([0 => 'A', 1 => 'B'], PromptOverrideNormalizer::normalize(['', 'A', '', 'B']));
    }

    #[Test]
    public function coercesNonStringElementsToStringRatherThanFailing(): void
    {
        // Defense-in-depth: the public Ai/AiRequestBuilder API only ever
        // passes string|array here, but a request DTO could in principle be
        // constructed directly with looser content — must not fatal.
        self::assertSame(['42'], PromptOverrideNormalizer::normalize([42]));
    }
}
