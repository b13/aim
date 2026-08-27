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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Complements Tests/Functional/Configuration/ModuleNavigationComponentTest.php,
 * which only proves the resolved value is correct for whichever single
 * TYPO3 major version this suite happens to run against (v14, per
 * composer.lock) - it can't exercise the v12 branch without a real v12
 * core, and a hardcoded single value that happens to match v14's expected
 * path would pass it undetected. This test instead inspects the raw
 * source directly, so it fails if the version-conditional itself is ever
 * accidentally simplified back to one hardcoded path (which would still
 * be correct for v14, wrong for v12) - see ModuleNavigationComponentTest's
 * own docblock for the full rationale (page-tree JS module moved from
 * @typo3/backend/page-tree/page-tree-element (v12.4 only) to
 * @typo3/backend/tree/page-tree-element (v13.4 on)).
 */
final class ModulesNavigationComponentSourceTest extends TestCase
{
    #[Test]
    public function navigationComponentIsVersionBranchedForBothPageTreeModulePaths(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../Configuration/Backend/Modules.php');
        self::assertNotFalse($source);

        // Isolate the navigationComponent assignment itself (a multi-line
        // ternary), not just "does either string appear anywhere in the
        // file" - other, unrelated version branches already exist in this
        // same file for a different purpose.
        self::assertMatchesRegularExpression(
            '/\'navigationComponent\'\s*=>.*?,/s',
            $source,
        );
        preg_match('/\'navigationComponent\'\s*=>.*?,/s', $source, $matches);
        $assignment = $matches[0];

        self::assertStringContainsString(
            '@typo3/backend/tree/page-tree-element',
            $assignment,
            'The v13+ page-tree module path must still be one of the two branches.',
        );
        self::assertStringContainsString(
            '@typo3/backend/page-tree/page-tree-element',
            $assignment,
            'The v12.4-only page-tree module path must still be one of the two branches - '
            . 'without it, the module silently loses its page tree on v12.',
        );
        self::assertStringContainsString(
            '$majorVersion',
            $assignment,
            'The two paths must be chosen by a real version check, not both present but unreachable.',
        );
    }
}
