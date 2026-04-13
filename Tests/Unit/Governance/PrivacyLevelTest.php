<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Governance;

use B13\Aim\Governance\PrivacyLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrivacyLevelTest extends TestCase
{
    #[Test]
    public function fromStringReturnsCorrectLevel(): void
    {
        self::assertSame(PrivacyLevel::Standard, PrivacyLevel::fromString('standard'));
        self::assertSame(PrivacyLevel::Reduced, PrivacyLevel::fromString('reduced'));
        self::assertSame(PrivacyLevel::None, PrivacyLevel::fromString('none'));
    }

    #[Test]
    public function fromStringDefaultsToStandardForInvalidInput(): void
    {
        self::assertSame(PrivacyLevel::Standard, PrivacyLevel::fromString(''));
        self::assertSame(PrivacyLevel::Standard, PrivacyLevel::fromString('invalid'));
    }

    public static function strictestProvider(): \Generator
    {
        yield 'standard + standard = standard' => [PrivacyLevel::Standard, PrivacyLevel::Standard, PrivacyLevel::Standard];
        yield 'standard + reduced = reduced' => [PrivacyLevel::Standard, PrivacyLevel::Reduced, PrivacyLevel::Reduced];
        yield 'standard + none = none' => [PrivacyLevel::Standard, PrivacyLevel::None, PrivacyLevel::None];
        yield 'reduced + standard = reduced' => [PrivacyLevel::Reduced, PrivacyLevel::Standard, PrivacyLevel::Reduced];
        yield 'reduced + reduced = reduced' => [PrivacyLevel::Reduced, PrivacyLevel::Reduced, PrivacyLevel::Reduced];
        yield 'reduced + none = none' => [PrivacyLevel::Reduced, PrivacyLevel::None, PrivacyLevel::None];
        yield 'none + standard = none' => [PrivacyLevel::None, PrivacyLevel::Standard, PrivacyLevel::None];
        yield 'none + reduced = none' => [PrivacyLevel::None, PrivacyLevel::Reduced, PrivacyLevel::None];
        yield 'none + none = none' => [PrivacyLevel::None, PrivacyLevel::None, PrivacyLevel::None];
    }

    #[Test]
    #[DataProvider('strictestProvider')]
    public function strictestReturnsCorrectLevel(PrivacyLevel $a, PrivacyLevel $b, PrivacyLevel $expected): void
    {
        self::assertSame($expected, $a->strictest($b));
    }

    #[Test]
    public function strictestIsCommutative(): void
    {
        $levels = [PrivacyLevel::Standard, PrivacyLevel::Reduced, PrivacyLevel::None];
        foreach ($levels as $a) {
            foreach ($levels as $b) {
                self::assertSame(
                    $a->strictest($b),
                    $b->strictest($a),
                    sprintf('strictest(%s, %s) should be commutative', $a->value, $b->value),
                );
            }
        }
    }
}
