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
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression test: the top-level "aim" module used to declare
 * 'access' => 'admin'. TYPO3's own ModuleProvider::filterInaccessibleSubModules()
 * prunes a submodule based on ITS OWN accessGranted() result before ever
 * looking at what's underneath it, so that admin-only gate removed "aim" -
 * and with it aim_prompt_management - from the module MENU for every
 * non-admin user, regardless of whether their own group's groupMods
 * explicitly granted that 'access' => 'user' submodule - defeating the
 * entire point of it not being admin-only. The fix removes the
 * parent-level gate; on TYPO3 v14+ the pre-existing 'dependsOnSubmodules'
 * appearance flag additionally keeps hiding the "AiM" entry entirely once
 * a user has zero accessible children (see Configuration/Backend/Modules.php
 * for the v12/13 cosmetic limitation this doesn't cover, since that flag
 * doesn't exist there).
 */
final class ModuleMenuAccessTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
        '../Tests/Functional/Fixtures/Extensions/test_sibling_module',
    ];

    /**
     * Matches Configuration/Backend/Modules.php's own $parent resolution:
     * TYPO3 core's "Admin Tools" identifier is "tools" on v12/13 and
     * "admin" (with "tools" kept only as an alias) from v14 on.
     */
    private function parentModuleIdentifier(): string
    {
        return (new Typo3Version())->getMajorVersion() >= 14 ? 'admin' : 'tools';
    }

    #[Test]
    public function aUserGrantedOnlyPromptManagementSeesTheAimEntryWithOnlyThatSubmodule(): void
    {
        $beGroups = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $beGroups->insert('be_groups', ['uid' => 1, 'title' => 'Prompt management only', 'groupMods' => 'aim_prompt_management']);

        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 1, 'username' => 'prompt-preview-user', 'admin' => 0, 'usergroup' => '1']);
        $backendUser = $this->setUpBackendUser(1);

        $menu = $this->get(ModuleProvider::class)->getModulesForModuleMenu($backendUser);

        // "aim" isn't a top-level menu entry itself - it's registered as a
        // child of TYPO3 core's own built-in Admin Tools module (see
        // Configuration/Backend/Modules.php's $parent), which has no access
        // restriction of its own.
        $parentIdentifier = $this->parentModuleIdentifier();
        self::assertArrayHasKey($parentIdentifier, $menu, 'Admin Tools must be visible once at least one of its descendants is granted.');
        $adminSubModules = $menu[$parentIdentifier]->getSubModules();
        self::assertArrayHasKey('aim', $adminSubModules, 'The "AiM" entry must be visible once at least one of its own submodules is granted.');
        $aimSubModules = $adminSubModules['aim']->getSubModules();
        self::assertArrayHasKey('aim_prompt_management', $aimSubModules, 'The specifically granted submodule must appear.');
        self::assertArrayNotHasKey('aim_providers', $aimSubModules, 'An admin-only sibling must stay hidden for this non-admin user.');
        self::assertArrayNotHasKey('aim_request_log', $aimSubModules, 'An admin-only sibling must stay hidden for this non-admin user.');
    }

    /**
     * Granting the user the "test_sibling_module" fixture (see
     * Tests/Functional/Fixtures/Extensions/test_sibling_module - an
     * unrelated, harmless module also parented under Admin Tools, also
     * 'access' => 'user') keeps Admin Tools itself genuinely present in the
     * menu, so this actually exercises "aim" specifically being pruned from
     * among several surviving siblings - rather than only ever observing
     * Admin Tools vanishing wholesale, which would pass even if "aim" were
     * never pruned on its own merits at all.
     *
     * Only holds from TYPO3 v14 on: the self-hiding behaviour this asserts
     * is driven entirely by 'appearance' => ['dependsOnSubmodules' => true]
     * (see Configuration/Backend/Modules.php), a key TYPO3 v12/13 core
     * doesn't read at all. On those versions "aim" survives with zero
     * children instead, which is the documented, unmitigated v12/13 gap -
     * not something this test can assert against without a real v12/13
     * core to run against.
     */
    #[Test]
    public function aUserGrantedNoAimSubmoduleAtAllDoesNotSeeTheAimEntry(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('dependsOnSubmodules only self-hides an empty "aim" entry from TYPO3 v14 on; see Configuration/Backend/Modules.php.');
        }

        $beGroups = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $beGroups->insert('be_groups', ['uid' => 2, 'title' => 'Sibling module only', 'groupMods' => 'test_sibling_module']);

        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 2, 'username' => 'no-aim-access', 'admin' => 0, 'usergroup' => '2']);
        $backendUser = $this->setUpBackendUser(2);

        $menu = $this->get(ModuleProvider::class)->getModulesForModuleMenu($backendUser);

        $parentIdentifier = $this->parentModuleIdentifier();
        self::assertArrayHasKey($parentIdentifier, $menu, 'Admin Tools must stay visible: the user has a genuine, unrelated submodule granted.');
        self::assertArrayNotHasKey('aim', $menu[$parentIdentifier]->getSubModules(), 'The "AiM" entry must stay hidden entirely for a user with zero accessible aim submodules.');
    }

    #[Test]
    public function anAdminSeesEveryAimSubmodule(): void
    {
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 3, 'username' => 'admin', 'admin' => 1]);
        $backendUser = $this->setUpBackendUser(3);

        $menu = $this->get(ModuleProvider::class)->getModulesForModuleMenu($backendUser);

        $parentIdentifier = $this->parentModuleIdentifier();
        self::assertArrayHasKey($parentIdentifier, $menu);
        $adminSubModules = $menu[$parentIdentifier]->getSubModules();
        self::assertArrayHasKey('aim', $adminSubModules);
        $aimSubModules = $adminSubModules['aim']->getSubModules();
        self::assertArrayHasKey('aim_providers', $aimSubModules);
        self::assertArrayHasKey('aim_request_log', $aimSubModules);
        self::assertArrayHasKey('aim_prompt_management', $aimSubModules);
        self::assertCount(3, $aimSubModules, 'Prompt Preview and Fragment Library are one merged module now, not two separate submodules.');
    }
}
