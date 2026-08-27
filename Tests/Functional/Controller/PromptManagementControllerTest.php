<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Controller;

use B13\Aim\Controller\PromptManagementController;
use B13\Aim\Controller\PromptManagementView;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the parts of previewAction's page validation that need a real
 * `pages` row and a real backend user: existence, and accessibility (the
 * actual security fix this listing rework was built for), neither of which is
 * unit-testable without booting TYPO3 (see
 * Tests/Unit/Controller/PromptManagementControllerTest.php for everything that
 * doesn't need the database, including the pageId=0 case).
 *
 * Page tree:
 *   1  "Siteroot"          -- used by the admin-user existence tests
 *   10 "Mounted"           -- perms_everybody grants PAGE_SHOW + CONTENT_EDIT; the restricted user's webmount
 *   20 "Not mounted"       -- perms_everybody grants PAGE_SHOW too, but outside the restricted user's mount
 *   30 "Subtree root"      -- has a fragment; used for overviewAction()'s tree-selection filtering
 *     31 "Subtree child"   -- has a fragment, child of 30
 *   40 "Outside subtree"   -- has a fragment, unrelated top-level page
 *   60 "Empty subtree"     -- no fragments anywhere in it; used for the
 *                             "wrong empty-state message" regression below
 *   70 "View-only"         -- perms_everybody grants PAGE_SHOW only, no CONTENT_EDIT
 */
final class PromptManagementControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Siteroot', 'is_siteroot' => 1]);
        // PAGE_SHOW (1) | CONTENT_EDIT (16): the fragment-editor (uid 51) is
        // mounted here and must be able to actually edit a fragment living
        // on this page, not merely see it.
        $pages->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Mounted', 'perms_everybody' => 17]);
        $pages->insert('pages', ['uid' => 20, 'pid' => 0, 'title' => 'Not mounted', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 30, 'pid' => 0, 'title' => 'Subtree root']);
        $pages->insert('pages', ['uid' => 31, 'pid' => 30, 'title' => 'Subtree child']);
        $pages->insert('pages', ['uid' => 40, 'pid' => 0, 'title' => 'Outside subtree']);
        $pages->insert('pages', ['uid' => 60, 'pid' => 0, 'title' => 'Empty subtree']);
        // PAGE_SHOW only, deliberately no CONTENT_EDIT: proves tables_modify
        // plus merely SEEING a page is not enough to earn an edit link.
        $pages->insert('pages', ['uid' => 70, 'pid' => 0, 'title' => 'View-only', 'perms_everybody' => 1]);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        foreach ([30 => 'F1', 31 => 'F2', 40 => 'F3'] as $pageId => $title) {
            $fragments->insert('tx_aim_prompt_fragment', ['pid' => $pageId, 'title' => $title, 'prompt' => 'Tone.', 'scope' => 'all']);
            $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => $pageId, 'parent_page' => $pageId, 'fragment' => (int)$fragments->lastInsertId()]);
        }

        $beGroups = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $beGroups->insert('be_groups', ['uid' => 60, 'title' => 'Fragment editors', 'tables_modify' => 'tx_aim_prompt_fragment']);
        // Browse-only: tables_select but not tables_modify - the "sees but
        // can't edit" role these tests exercise. Without this grant, the
        // module has nothing to show this user at all (see
        // hasFragmentReadAccess in the controller), which is a different,
        // coarser permission dimension than tables_modify.
        $beGroups->insert('be_groups', ['uid' => 61, 'title' => 'Fragment viewers', 'tables_select' => 'tx_aim_prompt_fragment']);

        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $beUsers->insert('be_users', ['uid' => 50, 'username' => 'restricted-editor', 'admin' => 0, 'db_mountpoints' => '10', 'usergroup' => '61']);
        $beUsers->insert('be_users', ['uid' => 51, 'username' => 'fragment-editor', 'admin' => 0, 'db_mountpoints' => '10,70', 'usergroup' => '60']);
    }

    #[Test]
    public function respondsOkTrueWhenTheSelectedPageExistsAndTheUserIsAnAdmin(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->preview(['pageId' => '1', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string)$response->getBody(), true)['ok']);
    }

    #[Test]
    public function respondsOkFalseWithA200WhenTheSelectedPageDoesNotExist(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->preview(['pageId' => '404404', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    /**
     * Regression test: this endpoint composes and returns the full,
     * untruncated text of every matching fragment - strictly more than
     * either listing view ever shows (a title, or a short crop). It must
     * enforce the exact same tables_select gate those views do (see this
     * test's own aUserWithoutTablesSelectSeesNoFragmentContentAtAll and
     * PromptManagementControllerLibraryTest's identical one for the Library
     * sub-action), or a user denied both listings could still read every
     * fragment's full content by posting here directly.
     */
    #[Test]
    public function respondsOkFalseForAUserWithoutTablesSelectRegardlessOfPageAccess(): void
    {
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 52, 'username' => 'no-fragment-access', 'admin' => 0, 'db_mountpoints' => '10']);
        $this->setUpBackendUser(52);

        $response = $this->preview(['pageId' => '10', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    /**
     * Regression test: this is a raw AJAX route (Configuration/Backend/AjaxRoutes.php),
     * never validated by BackendModuleValidator - unlike the aim_prompt_management
     * module ('access' => 'user' in Configuration/Backend/Modules.php)
     * whose doc-header button triggers it. Nothing previously stopped any
     * authenticated backend user, regardless of whether their group was
     * ever granted that module at all, from calling this directly and
     * triggering a real, billable LLM call on arbitrary attacker-supplied
     * text.
     */
    #[Test]
    public function calibrateVoiceActionDeniesAUserWithoutModuleAccess(): void
    {
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 53, 'username' => 'no-module-access', 'admin' => 0]);
        $this->setUpBackendUser(53);

        $controller = $this->get(PromptManagementController::class);
        $request = (new ServerRequest())->withParsedBody(['text' => 'Some page content to calibrate.']);
        $response = $controller->calibrateVoiceAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    /**
     * The allow-direction counterpart to the test above: proves the gate
     * actually checks the real module identifier (aim_prompt_management)
     * rather than, say, a typo'd or stale one that would deny everyone -
     * the deny-only test above couldn't tell those two failure modes apart
     * on its own.
     */
    #[Test]
    public function calibrateVoiceActionAllowsAUserGrantedTheModule(): void
    {
        $beGroups = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $beGroups->insert('be_groups', ['uid' => 70, 'title' => 'Prompt management', 'groupMods' => 'aim_prompt_management']);
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 55, 'username' => 'module-access', 'admin' => 0, 'usergroup' => '70']);
        $this->setUpBackendUser(55);

        $controller = $this->get(PromptManagementController::class);
        $request = (new ServerRequest())->withParsedBody(['text' => 'Some page content to calibrate.']);
        $response = $controller->calibrateVoiceAction($request);

        self::assertNotSame(403, $response->getStatusCode(), 'A user granted the module must pass the access gate, whatever the calibration outcome itself is.');
    }

    /**
     * Regression test: same raw-AJAX-route gap as calibrateVoiceAction()
     * above - this is that same modal's page-picker feeder.
     */
    #[Test]
    public function extractPageContentActionDeniesAUserWithoutModuleAccess(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 61, 'pid' => 0, 'title' => 'Page for extraction', 'perms_everybody' => 1,
        ]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => 61,
            'CType' => 'text',
            'bodytext' => 'Secret page content that must never leak through this endpoint.',
        ]);
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        // db_mountpoints deliberately grants webmount access to page 61 -
        // this user is denied by the module-access gate ALONE, not
        // incidentally by the pre-existing webmount scoping too. Without
        // that mountpoint, this test couldn't tell the new gate apart from
        // the unrelated, already-correct "page not in webmount" case.
        $beUsers->insert('be_users', ['uid' => 54, 'username' => 'no-module-access-2', 'admin' => 0, 'db_mountpoints' => '61']);
        $this->setUpBackendUser(54);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $controller = $this->get(PromptManagementController::class);
        $request = (new ServerRequest())->withParsedBody(['pageUids' => [61]]);
        $response = $controller->extractPageContentAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
        self::assertArrayNotHasKey('text', $body, 'The extracted page text must never be returned to a user without module access.');
    }

    /**
     * The allow-direction counterpart to the test above - see
     * calibrateVoiceActionAllowsAUserGrantedTheModule()'s identical
     * rationale.
     */
    #[Test]
    public function extractPageContentActionAllowsAUserGrantedTheModule(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 62, 'pid' => 0, 'title' => 'Page for extraction', 'perms_everybody' => 1,
        ]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => 62,
            'CType' => 'text',
            'bodytext' => 'Some real page content.',
        ]);
        $beGroups = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $beGroups->insert('be_groups', ['uid' => 71, 'title' => 'Prompt management', 'groupMods' => 'aim_prompt_management']);
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 56, 'username' => 'module-access-2', 'admin' => 0, 'db_mountpoints' => '62', 'usergroup' => '71']);
        $this->setUpBackendUser(56);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $controller = $this->get(PromptManagementController::class);
        $request = (new ServerRequest())->withParsedBody(['pageUids' => [62]]);
        $response = $controller->extractPageContentAction($request);

        self::assertNotSame(403, $response->getStatusCode(), 'A user granted the module must pass the access gate.');
        $body = json_decode((string)$response->getBody(), true);
        self::assertTrue($body['ok']);
        self::assertStringContainsString('Some real page content.', $body['text']);
    }

    #[Test]
    public function respondsOkTrueForARestrictedUserPreviewingAPageInsideTheirOwnWebmount(): void
    {
        $this->setUpBackendUser(50);

        $response = $this->preview(['pageId' => '10', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string)$response->getBody(), true)['ok']);
    }

    #[Test]
    public function respondsOkFalseForARestrictedUserPreviewingAnExistingPageOutsideTheirWebmount(): void
    {
        // This is the actual security fix this module's listing rework was
        // built for: page 20 exists and is publicly PAGE_SHOW-permitted
        // (perms_everybody), but the restricted user's webmount is only
        // page 10, so the request must be rejected regardless of the page's
        // own permission bits.
        $this->setUpBackendUser(50);

        $response = $this->preview(['pageId' => '20', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function overviewActionWithNoPageSelectedListsPagesFromTheWholeInstallation(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(0)->getBody();

        self::assertStringContainsString('Subtree root', $body);
        self::assertStringContainsString('Subtree child', $body);
        self::assertStringContainsString('Outside subtree', $body);
    }

    /**
     * Regression test: the Pages/Library sub-action switcher must render
     * both boxes (headline + description each), and the Pages box must
     * carry the active-state class/attribute on the default (no `view`
     * query param) request - not the Library one.
     */
    #[Test]
    public function theViewSwitchBoxesRenderWithPagesActiveByDefault(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(0)->getBody();

        self::assertMatchesRegularExpression(
            '/<a\s+href="[^"]*"\s+class="aim-view-box aim-view-box--active"\s+aria-current="page"\s*>\s*<span class="aim-view-box__headline">Pages<\/span>\s*<span class="aim-view-box__description">[^<]+<\/span>\s*<\/a>/',
            $body,
            'The Pages box must be the active one by default.',
        );
        self::assertMatchesRegularExpression(
            '/<a\s+href="[^"]*"\s+class="aim-view-box\s*"\s*>\s*<span class="aim-view-box__headline">Library<\/span>/',
            $body,
            'The Library box must render, inactive (no aria-current attribute).',
        );
        self::assertDoesNotMatchRegularExpression(
            '/class="aim-view-box\s*"\s+aria-current/',
            $body,
            'An inactive box must not carry an aria-current attribute at all, not merely an empty one.',
        );
    }

    /**
     * Regression test: see PromptManagementControllerLibraryTest's identical
     * one for the full rationale - this custom listing bypasses
     * DataHandler/the standard record list, so nothing else would enforce
     * tables_select for it.
     */
    #[Test]
    public function aUserWithoutTablesSelectSeesNoFragmentContentAtAll(): void
    {
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 52, 'username' => 'no-fragment-access', 'admin' => 0, 'db_mountpoints' => '10']);
        $this->setUpBackendUser(52);

        $body = (string)$this->overview(0)->getBody();

        self::assertStringNotContainsString('Subtree root', $body);
        self::assertStringNotContainsString('Outside subtree', $body);
        self::assertStringContainsString("doesn&#039;t grant read access", $body);
    }

    #[Test]
    public function overviewActionWithAPageSelectedNarrowsTheListToItsSubtree(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(30)->getBody();

        self::assertStringContainsString('Subtree root', $body);
        self::assertStringContainsString('Subtree child', $body);
        self::assertStringNotContainsString('Outside subtree', $body);
    }

    #[Test]
    public function selectingAPageAlwaysOffersAPreviewThisPageActionRegardlessOfWhetherItOwnsAFragment(): void
    {
        // Page 60 owns no fragment (so it's never a row in the list), but it
        // might still inherit one from an ancestor, which is exactly why
        // this standalone trigger exists: to make that checkable even then.
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(60)->getBody();

        self::assertStringContainsString('pp-panel-selected', $body);
        self::assertStringContainsString('data-page-id="60"', $body);
    }

    #[Test]
    public function noPageSelectedShowsNoPreviewThisPageAction(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(0)->getBody();

        self::assertStringNotContainsString('pp-panel-selected', $body);
    }

    #[Test]
    public function selectingASubtreeWithNoFragmentsShowsTheSubtreeSpecificEmptyStateNotTheInstallationWideOne(): void
    {
        // Regression: a selected page/subtree with zero fragments must not
        // claim "no page in this installation has a fragment", since pages
        // 30/31/40 in the same installation DO have fragments, just not under
        // page 60.
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(60)->getBody();

        self::assertStringContainsString('nor any of its subpages has its own AI prompt fragment configured', $body);
        self::assertStringNotContainsString('No page in this installation has an AI prompt fragment configured yet', $body);
    }

    #[Test]
    public function theSubtreeSpecificEmptyStateOffersACreatePromptLinkWhenTheUserCanEditTheSelectedPage(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(60)->getBody();

        self::assertStringContainsString('columnsOnly%5Bpages%5D%5B0%5D=tx_aim_prompt_fragments', $body);
    }

    /**
     * Regression test: this link opens the page edit form's own `fragment`
     * picker (PromptFragmentSuggestReceiver), which lets the user browse
     * titles from the whole accessible fragment library - it used to be
     * offered on PAGE_EDIT alone, letting a user without tables_select for
     * tx_aim_prompt_fragment reach fragment content through this side door,
     * bypassing the very check this module enforces on its own listings.
     */
    #[Test]
    public function theCreatePromptLinkIsHiddenFromAUserWithoutFragmentReadAccess(): void
    {
        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        // PAGE_EDIT on page 10 (mounted, perms_everybody grants CONTENT_EDIT),
        // but no usergroup at all - not tables_select, not tables_modify.
        $beUsers->insert('be_users', ['uid' => 57, 'username' => 'no-fragment-access-editor', 'admin' => 0, 'db_mountpoints' => '10']);
        $this->setUpBackendUser(57);

        $body = (string)$this->overview(10)->getBody();

        self::assertStringNotContainsString('columnsOnly%5Bpages%5D%5B0%5D=tx_aim_prompt_fragments', $body);
    }

    /**
     * Regression test: unlike renderLibraryView()'s identical
     * $treeAwareParameters (see PromptManagementControllerLibraryTest's own
     * paginationLinksPreserveTheActiveFilterAndSort), the Pages sub-action's
     * pagination/sort links never included an explicit 'view' => 'pages'
     * parameter - so following one of these links (bookmarking it, or
     * clicking it from a second tab after switching to Library elsewhere)
     * made prompt-preview.js's own "page tree dropped every param, view
     * included" heuristic mistake it for an actual page-tree navigation,
     * silently bouncing the user to whichever view was last stored in
     * localStorage and dropping their filter/sort/page state. Needs > limit
     * (15) matching pages so pagination actually renders at all.
     */
    #[Test]
    public function paginationLinksPreserveTheActiveFilterAndSortAndView(): void
    {
        $this->setUpBackendUser(1);
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        for ($i = 1; $i <= 20; $i++) {
            $pageUid = 500 + $i;
            $pages->insert('pages', ['uid' => $pageUid, 'pid' => 0, 'title' => 'Paginated page ' . $i]);
            $fragments->insert('tx_aim_prompt_fragment', [
                'pid' => $pageUid,
                'title' => 'Paginated fragment ' . $i,
                'prompt' => 'searchable-marker',
                'scope' => 'text',
            ]);
            $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => $pageUid, 'parent_page' => $pageUid, 'fragment' => (int)$fragments->lastInsertId()]);
        }

        $body = (string)$this->pagesView([
            'demand' => ['scope' => 'text', 'search' => 'searchable-marker'],
            'orderField' => 'title',
            'orderDirection' => 'desc',
        ])->getBody();

        self::assertMatchesRegularExpression('/data-navigate-value="[^"]*"/', $body, 'Pagination did not render at all - fixture must yield > 15 matching pages.');
        preg_match('/data-navigate-value="([^"]*)"/', $body, $matches);
        $paginationUrl = $matches[1] ?? '';

        self::assertStringContainsString('demand%5Bscope%5D=text', $paginationUrl);
        self::assertStringContainsString('demand%5Bsearch%5D=searchable-marker', $paginationUrl);
        self::assertStringContainsString('orderField=title', $paginationUrl);
        self::assertStringContainsString('orderDirection=desc', $paginationUrl);
        self::assertStringContainsString('view=pages', $paginationUrl, 'Without an explicit view=pages, the JS view-switch heuristic mistakes this link for a page-tree navigation.');
    }

    /**
     * Regression test: the Pages and Library sub-views used to each define
     * their own near-identical filter form (PromptManagementFilter.html now
     * consolidates both), and the two copies had silently drifted apart -
     * Library's own "Reset" link always carried an explicit view=library,
     * but Pages' own copy carried no view parameter at all. Same
     * prompt-preview.js heuristic as
     * paginationLinksPreserveTheActiveFilterAndSortAndView() above: a
     * view-less request reads as "no explicit choice", so a Pages-view user
     * whose last EXPLICITLY chosen view (possibly from an earlier session)
     * was Library could click "Reset" on the page they are currently
     * looking at and silently get redirected to Library instead.
     */
    #[Test]
    public function theResetLinkExplicitlyCarriesTheActiveView(): void
    {
        $this->setUpBackendUser(1);

        $pagesBody = (string)$this->pagesView()->getBody();
        preg_match('/<a href="([^"]*)" class="btn btn-link">Reset<\/a>/', $pagesBody, $matches);
        $pagesResetUrl = $matches[1] ?? '';
        self::assertNotSame('', $pagesResetUrl, 'Reset link not found in the Pages view.');
        self::assertStringContainsString('view=pages', $pagesResetUrl, 'The Pages view\'s own Reset link must explicitly carry view=pages, or it reads as an ambiguous view-less request client-side.');
    }

    /**
     * Regression test: the "Fragments" column used to show a bare count with
     * no way to see which fragments those were, or jump to editing one,
     * without leaving this list for the page's own edit form, mirroring the
     * Library module's "used on N page(s)" disclosure (see
     * PromptManagementControllerLibraryTest), just in the other direction (a
     * page's own fragments, not a fragment's usage pages).
     */
    #[Test]
    public function theFragmentsColumnExpandsIntoTheIndividualFragmentsWithADirectEditLink(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(0)->getBody();

        self::assertStringContainsString('aim-fragments-rows-30', $body);
        self::assertMatchesRegularExpression('/1 fragment\(s\)/', $body);
        self::assertStringContainsString('>F1<', $body);
    }

    /**
     * Regression test: the fragment edit link in the expanded "Fragments"
     * column used to render unconditionally, unlike the sibling page-column
     * link (guarded by {page.canEdit}). A user without `tables_modify` for
     * tx_aim_prompt_fragment (a table-level permission, not per-record)
     * would follow it straight into an "access denied" dead end.
     *
     * Covers all three cases, not just admin-vs-permissionless: a plain
     * non-admin user (uid 50, no usergroup at all) is denied, but a
     * non-admin user whose group explicitly grants `tables_modify` for
     * tx_aim_prompt_fragment (uid 51) is still granted the link - proving
     * this checks that specific permission rather than merely isAdmin() or
     * the wrong table.
     */
    #[Test]
    public function theFragmentEditLinkIsShownOnlyToAUserAllowedToModifyFragments(): void
    {
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 10, 'title' => 'Mounted fragment', 'prompt' => 'Tone.', 'scope' => 'all']);
        $fragmentUid = (int)$fragments->lastInsertId();
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 10, 'parent_page' => 10, 'fragment' => $fragmentUid]);

        $this->setUpBackendUser(1);
        $adminBody = (string)$this->overview(0)->getBody();
        self::assertMatchesRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $fragmentUid . '%5D=edit/', $adminBody);

        $this->setUpBackendUser(50);
        $restrictedBody = (string)$this->overview(0)->getBody();
        self::assertStringContainsString('Mounted fragment', $restrictedBody);
        self::assertDoesNotMatchRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $fragmentUid . '%5D=edit/', $restrictedBody);

        $this->setUpBackendUser(51);
        $fragmentEditorBody = (string)$this->overview(0)->getBody();
        self::assertMatchesRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $fragmentUid . '%5D=edit/', $fragmentEditorBody);
    }

    /**
     * Regression test: tables_modify for tx_aim_prompt_fragment is
     * necessary but not sufficient for a pid>0 (sysfolder-stored) fragment
     * either - DataHandler's hasPageContextPermission() also requires
     * Permission::CONTENT_EDIT on the fragment's own containing page,
     * independent of tables_modify. Page 70 grants the fragment-editor
     * (uid 51) only PAGE_SHOW (they can see and browse the fragment there),
     * deliberately withholding CONTENT_EDIT - the edit link must not
     * appear, even though it correctly does for the otherwise-identical
     * "Mounted fragment" on page 10 (which grants both).
     */
    #[Test]
    public function theFragmentEditLinkIsNotShownForAFragmentOnAPageTheUserCanSeeButNotContentEdit(): void
    {
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 70, 'title' => 'View-only fragment', 'prompt' => 'Tone.', 'scope' => 'all']);
        $fragmentUid = (int)$fragments->lastInsertId();
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 70, 'parent_page' => 70, 'fragment' => $fragmentUid]);

        $this->setUpBackendUser(51);
        $body = (string)$this->overview(0)->getBody();

        self::assertStringContainsString('View-only fragment', $body);
        self::assertDoesNotMatchRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $fragmentUid . '%5D=edit/', $body);
    }

    /**
     * Regression test: the Pages sub-action's own per-fragment edit link
     * (inside a page row's expanded "Fragments" list) needs the same pid=0
     * guard as the Library sub-action's - covered separately here since
     * overviewAction() computes 'canEdit' on its own fragments array,
     * independent of renderLibraryView()'s.
     */
    #[Test]
    public function theFragmentEditLinkForAGlobalPidZeroFragmentIsShownOnlyToAnAdmin(): void
    {
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Global fragment', 'prompt' => 'Tone.', 'scope' => 'all']);
        $fragmentUid = (int)$fragments->lastInsertId();
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 10, 'parent_page' => 10, 'fragment' => $fragmentUid]);

        $this->setUpBackendUser(51);
        $fragmentEditorBody = (string)$this->overview(0)->getBody();
        self::assertStringContainsString('Global fragment', $fragmentEditorBody);
        self::assertDoesNotMatchRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $fragmentUid . '%5D=edit/', $fragmentEditorBody);

        $this->setUpBackendUser(1);
        $adminBody = (string)$this->overview(0)->getBody();
        self::assertMatchesRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $fragmentUid . '%5D=edit/', $adminBody);
    }

    /**
     * @param array<string, string> $body
     */
    private function preview(array $body): ResponseInterface
    {
        $controller = $this->get(PromptManagementController::class);
        $request = (new ServerRequest())->withParsedBody($body);

        return $controller->previewAction($request);
    }

    private function overview(int $selectedPageId): ResponseInterface
    {
        // ModuleTemplate reads $GLOBALS['LANG'] directly. setUpBackendUser()
        // populates $GLOBALS['BE_USER'] but not this, so overviewAction()
        // (unlike previewAction(), which never touches ModuleTemplate) needs
        // it set up explicitly first.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $controller = $this->get(PromptManagementController::class);
        $request = $this->buildRequestWithNormalizedParams($selectedPageId);

        return $controller->overviewAction($request);
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function pagesView(array $queryParams = []): ResponseInterface
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $controller = $this->get(PromptManagementController::class);
        $url = 'https://typo3-testing.local/typo3/module/admin/aim/prompt-management';
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3/module/admin/aim/prompt-management',
            'REQUEST_METHOD' => 'GET',
        ];
        $queryParams = array_merge(['view' => 'pages'], $queryParams);
        $request = new ServerRequest($url, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('route', new Route('/module/admin/aim/prompt-management', ['packageName' => 'b13/aim']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $request = $request->withQueryParams($queryParams);
        // In production, BackendModuleValidator resolves and attaches this
        // before the controller ever runs; these tests call the controller
        // directly, bypassing that middleware, so it's simulated here with
        // whatever this call's own 'view' query param says (mirroring what
        // the middleware would have persisted from an identical real
        // request).
        $request = $request->withAttribute('moduleData', new ModuleData('aim_prompt_management', ['view' => $queryParams['view']]));

        return $controller->overviewAction($request);
    }

    private function buildRequestWithNormalizedParams(int $selectedPageId): ServerRequest
    {
        $url = 'https://typo3-testing.local/typo3/module/admin/aim/prompt-management' . ($selectedPageId > 0 ? '?id=' . $selectedPageId : '');
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => parse_url($url, PHP_URL_PATH) . ($selectedPageId > 0 ? '?id=' . $selectedPageId : ''),
            'REQUEST_METHOD' => 'GET',
        ];
        $request = new ServerRequest($url, 'GET', 'php://input', [], $serverParams);
        $request = $request->withQueryParams($selectedPageId > 0 ? ['id' => (string)$selectedPageId] : []);
        $request = $request->withAttribute('route', new Route('/module/admin/aim/prompt-management', ['packageName' => 'b13/aim']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        // See pagesView()'s identical comment: simulates what
        // BackendModuleValidator would have attached. This helper is only
        // ever used for the Pages view, so 'view' is fixed here.
        return $request->withAttribute('moduleData', new ModuleData('aim_prompt_management', ['view' => PromptManagementView::Pages->value]));
    }
}
