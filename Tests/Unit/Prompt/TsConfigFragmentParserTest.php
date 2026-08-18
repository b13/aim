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

use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Prompt\TsConfigFragmentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TsConfigFragmentParserTest extends TestCase
{
    #[Test]
    public function parsesMultipleFragmentsDefaultingScopeToAll(): void
    {
        $result = TsConfigFragmentParser::parse([
            'watermark.' => ['prompt' => 'Add a watermark.', 'scope' => 'imageGeneration'],
            'brandVoice.' => ['prompt' => 'Write in a warm voice.'],
        ]);

        self::assertSame(
            [
                ['prompt' => 'Add a watermark.', 'scope' => ['imageGeneration']],
                ['prompt' => 'Write in a warm voice.', 'scope' => [PromptFragmentScope::All->value]],
            ],
            $result,
        );
    }

    #[Test]
    public function parsesACommaSeparatedScopeIntoMultipleValues(): void
    {
        $result = TsConfigFragmentParser::parse([
            'watermark.' => ['prompt' => 'Add a watermark.', 'scope' => 'text,imageGeneration'],
        ]);

        self::assertSame([['prompt' => 'Add a watermark.', 'scope' => ['text', 'imageGeneration']]], $result);
    }

    #[Test]
    public function dropsEntriesMissingATrailingDotKey(): void
    {
        self::assertSame([], TsConfigFragmentParser::parse([
            'watermark' => ['prompt' => 'Should be ignored — key has no trailing dot.'],
        ]));
    }

    #[Test]
    public function dropsEntriesWhoseValueIsNotAnArray(): void
    {
        self::assertSame([], TsConfigFragmentParser::parse([
            'watermark.' => 'not an array',
        ]));
    }

    #[Test]
    public function dropsEntriesWithMissingOrBlankPrompt(): void
    {
        self::assertSame([], TsConfigFragmentParser::parse([
            'noPrompt.' => ['scope' => 'text'],
            'blankPrompt.' => ['prompt' => '   '],
        ]));
    }

    #[Test]
    public function dropsEntriesWithNonStringPrompt(): void
    {
        self::assertSame([], TsConfigFragmentParser::parse([
            'wrongType.' => ['prompt' => 42],
        ]));
    }

    #[Test]
    public function treatsBlankScopeAsAll(): void
    {
        self::assertSame(
            [['prompt' => 'Hello.', 'scope' => [PromptFragmentScope::All->value]]],
            TsConfigFragmentParser::parse(['x.' => ['prompt' => 'Hello.', 'scope' => '']]),
        );
    }

    #[Test]
    public function returnsEmptyListForEmptyInput(): void
    {
        self::assertSame([], TsConfigFragmentParser::parse([]));
    }

    #[Test]
    public function normalizesUnknownScopeToAllInsteadOfSilentlyKeepingIt(): void
    {
        $result = TsConfigFragmentParser::parse([
            'watermark.' => ['prompt' => 'Add a watermark.', 'scope' => 'imageGeneraton'], // typo
        ]);

        self::assertSame(
            [['prompt' => 'Add a watermark.', 'scope' => [PromptFragmentScope::All->value]]],
            $result,
        );
    }

    #[Test]
    public function logsAWarningWhenNormalizingAnUnknownScope(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('imageGeneraton'));

        TsConfigFragmentParser::parse(
            ['watermark.' => ['prompt' => 'Add a watermark.', 'scope' => 'imageGeneraton']],
            $logger,
        );
    }

    #[Test]
    public function doesNotLogWhenNoLoggerIsGiven(): void
    {
        // Must not throw despite the unknown scope — $logger is optional.
        self::assertSame(
            [['prompt' => 'Add a watermark.', 'scope' => [PromptFragmentScope::All->value]]],
            TsConfigFragmentParser::parse(['watermark.' => ['prompt' => 'Add a watermark.', 'scope' => 'bogus']]),
        );
    }

    #[Test]
    public function doesNotLogForKnownScopes(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        TsConfigFragmentParser::parse(
            ['watermark.' => ['prompt' => 'Add a watermark.', 'scope' => 'imageGeneration']],
            $logger,
        );
    }
}
