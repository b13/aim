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
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Regression test for a real bug confirmed live: without an explicit
 * `pid` option, TYPO3 core's AddRecord::resolvePid() falls through to
 * "current pid by default" for a field whose table has no fixed
 * rootLevel=1 (tx_aim_prompt_fragment's rootLevel=-1 doesn't qualify) - so
 * a fragment created inline via this field's own "+" button permanently
 * lands on whatever content page happened to be open, not the reusable
 * library location (pid=0 or the site root) every other part of this
 * extension assumes. Verified live: on a subpage, the "+" link's own
 * target pid changed from the subpage's own uid to its site root's uid
 * once `'pid' => '###SITEROOT###'` was added here.
 *
 * A plain TCA-array assertion, not a full FormEngine/AddRecord rendering
 * test: the substitution itself (###SITEROOT### -> the real site root id)
 * is TYPO3 core's own, already-tested behavior (AddRecord::resolvePid()) -
 * this only guards against someone accidentally removing or changing the
 * marker in this extension's own TCA.
 */
final class TcaPagePromptFragmentAddRecordPidTest extends UnitTestCase
{
    #[Test]
    public function theFragmentFieldsAddRecordButtonTargetsTheSiteRootNotTheCurrentPage(): void
    {
        $tca = require __DIR__ . '/../../../Configuration/TCA/tx_aim_page_prompt_fragment.php';

        self::assertSame(
            '###SITEROOT###',
            $tca['columns']['fragment']['config']['fieldControl']['addRecord']['options']['pid'] ?? null,
        );
    }
}
