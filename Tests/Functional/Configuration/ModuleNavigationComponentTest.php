<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleRegistry;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * NOT a guard against the version conditional being simplified back to a
 * single hardcoded path - this suite only ever runs against one real
 * TYPO3 core (v14.3, per composer.lock), and v14's own expected value
 * happens to equal the pre-fix hardcoded one, so this test alone cannot
 * tell "properly version-branched" apart from "hardcoded to the v14
 * value" (confirmed: it still passes if the conditional in Configuration/
 * Backend/Modules.php is reverted to that hardcoded string). That guard
 * lives in Tests/Unit/Configuration/ModulesNavigationComponentSourceTest.php
 * instead, via direct source inspection.
 *
 * What this test DOES prove, that the unit test can't: BaseModule's own
 * `inheritNavigationComponent` (default true) makes a module fall back to
 * its PARENT's navigationComponent whenever its own value is empty -
 * exercised here through the real ModuleRegistry rather than the raw
 * config array, so a future refactor that accidentally empties this
 * module's own key (relying on inheriting `aim`'s, which has none) would
 * be caught here.
 */
final class ModuleNavigationComponentTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function promptManagementModuleExposesItsOwnNavigationComponentRatherThanInheritingFromTheParent(): void
    {
        $majorVersion = (new Typo3Version())->getMajorVersion();
        $expected = $majorVersion >= 13
            ? '@typo3/backend/tree/page-tree-element'
            : '@typo3/backend/page-tree/page-tree-element';

        $module = $this->get(ModuleRegistry::class)->getModule('aim_prompt_management');

        self::assertSame($expected, $module->getNavigationComponent());
    }
}
