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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PromptFragmentScopeTest extends TestCase
{
    #[Test]
    public function aSingleValueRoundTripsUnchanged(): void
    {
        self::assertSame(['text'], PromptFragmentScope::parseList('text'));
    }

    #[Test]
    public function aCommaSeparatedListParsesToMultipleValidatedValues(): void
    {
        self::assertSame(['text', 'vision'], PromptFragmentScope::parseList('text,vision'));
    }

    #[Test]
    public function anArrayInputWorksIdenticallyToItsCommaStringEquivalent(): void
    {
        self::assertSame(
            PromptFragmentScope::parseList('text,vision'),
            PromptFragmentScope::parseList(['text', 'vision']),
        );
    }

    #[Test]
    public function anUnknownTokenAmongValidOnesIsDroppedWithoutInvalidatingTheRest(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        self::assertSame(['text', 'vision'], PromptFragmentScope::parseList('text,bogus,vision', $logger));
    }

    #[Test]
    public function anEmptyInputFallsBackToAll(): void
    {
        self::assertSame(['all'], PromptFragmentScope::parseList(''));
    }

    #[Test]
    public function anEntirelyInvalidInputFallsBackToAll(): void
    {
        self::assertSame(['all'], PromptFragmentScope::parseList('bogus,alsoBogus'));
    }

    #[Test]
    public function duplicateTokensAreDeduplicated(): void
    {
        self::assertSame(['text'], PromptFragmentScope::parseList('text,text'));
    }

    #[Test]
    public function surroundingWhitespaceIsTrimmedFromEachToken(): void
    {
        self::assertSame(['text', 'vision'], PromptFragmentScope::parseList(' text , vision '));
    }

    #[Test]
    public function aFragmentTaggedWithTheLiteralAllSentinelMatchesEveryRequestedScope(): void
    {
        self::assertTrue(PromptFragmentScope::Text->matches(['all']));
        self::assertTrue(PromptFragmentScope::ImageGeneration->matches(['all']));
    }

    #[Test]
    public function requestingAllCapabilitiesMatchesAnyFragmentRegardlessOfItsOwnScopes(): void
    {
        // New fragments default to all 6 concrete capabilities individually
        // checked, not to the literal "all" sentinel — a naive symmetric
        // in_array() check would make "preview/filter as All capabilities"
        // match almost nothing. Requesting All must match unconditionally.
        self::assertTrue(PromptFragmentScope::All->matches(['text']));
        self::assertTrue(PromptFragmentScope::All->matches(['text', 'vision']));
        self::assertTrue(PromptFragmentScope::All->matches([]));
    }

    #[Test]
    public function aSpecificRequestedScopeStillOnlyMatchesFragmentsTaggedWithItOrAll(): void
    {
        self::assertTrue(PromptFragmentScope::Text->matches(['text', 'vision']));
        self::assertFalse(PromptFragmentScope::Text->matches(['vision']));
    }
}
