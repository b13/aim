<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Utility;

use B13\Aim\Utility\JsonExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonExtractorTest extends TestCase
{
    #[Test]
    public function extractsCleanJson(): void
    {
        $json = JsonExtractor::extractJsonObject('{"a": 1, "b": "x"}');
        self::assertSame('{"a": 1, "b": "x"}', $json);
    }

    #[Test]
    public function stripsMarkdownFences(): void
    {
        $json = JsonExtractor::extractJsonObject("```json\n{\"a\": 1}\n```");
        self::assertSame('{"a": 1}', $json);
    }

    #[Test]
    public function stripsFenceWithoutLanguageHint(): void
    {
        $json = JsonExtractor::extractJsonObject("```\n{\"a\": 1}\n```");
        self::assertSame('{"a": 1}', $json);
    }

    #[Test]
    public function extractsFirstBalancedObjectFromSurroundingProse(): void
    {
        $json = JsonExtractor::extractJsonObject('Sure, here you go: {"a": 1} — hope that helps!');
        self::assertSame('{"a": 1}', $json);
    }

    #[Test]
    public function returnsNullForEmptyString(): void
    {
        self::assertNull(JsonExtractor::extractJsonObject(''));
        self::assertNull(JsonExtractor::extractJsonObject('   '));
    }

    #[Test]
    public function returnsNullWhenNoBracesPresent(): void
    {
        self::assertNull(JsonExtractor::extractJsonObject('not json at all'));
    }

    #[Test]
    public function returnsNullWhenClosingBraceComesBeforeOpeningBrace(): void
    {
        self::assertNull(JsonExtractor::extractJsonObject('} weird {'));
    }
}
