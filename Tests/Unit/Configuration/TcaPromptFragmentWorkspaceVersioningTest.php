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
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * `versioningWS_alwaysAllowLiveEdit` next to `versioningWS` sends a workspace
 * edit straight to live on TYPO3 12.4 to 14.3.7, and TYPO3 14.3.8 removes it
 * again with a TCA migration deprecation.
 */
final class TcaPromptFragmentWorkspaceVersioningTest extends UnitTestCase
{
    public static function tableDataProvider(): iterable
    {
        yield 'tx_aim_prompt_fragment' => ['tx_aim_prompt_fragment'];
        yield 'tx_aim_page_prompt_fragment' => ['tx_aim_page_prompt_fragment'];
    }

    #[Test]
    #[DataProvider('tableDataProvider')]
    public function tableIsVersionedInWorkspacesWithoutLiveEdit(string $table): void
    {
        $tca = require __DIR__ . '/../../../Configuration/TCA/' . $table . '.php';

        self::assertTrue($tca['ctrl']['versioningWS'] ?? false);
        self::assertArrayNotHasKey('versioningWS_alwaysAllowLiveEdit', $tca['ctrl']);
    }
}
