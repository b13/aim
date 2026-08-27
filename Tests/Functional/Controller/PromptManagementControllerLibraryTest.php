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
 * Covers the Library sub-action (`?view=library`) of the merged
 * aim_prompt_management module: browsing the reusable fragment pool
 * itself, plus the "used on N pages" usage count this view exists to
 * answer.
 *
 * Page tree / fixtures:
 *   10 "Mounted"                       -- perms_everybody grants PAGE_SHOW; the restricted user's webmount
 *   20 "Not mounted"                   -- perms_everybody grants PAGE_SHOW + CONTENT_EDIT, but outside the restricted user's mount
 *   30 "Hidden page"                   -- hidden=1, also assigned "Reused fragment"
 *   70 "Sysfolder, view-only"          -- perms_everybody grants PAGE_SHOW only, no CONTENT_EDIT
 *
 * Fragments:
 *   "Reused fragment"        (pid=0, global) -- assigned to page 10, page 20 and the hidden page 30
 *   "Unused fragment"        (pid=0, global) -- no assignments at all
 *   "Sysfolder fragment"     (pid=20)        -- lives inside a page the restricted user cannot see
 *   "View-only sysfolder fragment" (pid=70)  -- lives on a page the fragment-editor CAN see but not content-edit
 */
final class PromptManagementControllerLibraryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private int $reusedFragmentUid;
    private int $unusedFragmentUid;
    private int $sysfolderFragmentUid;
    private int $viewOnlySysfolderFragmentUid;

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Mounted', 'perms_everybody' => 1]);
        // PAGE_SHOW (1) | CONTENT_EDIT (16): the fragment-editor (uid 51) is
        // mounted here and must be able to actually edit the fragment
        // living in this sysfolder, not merely see it.
        $pages->insert('pages', ['uid' => 20, 'pid' => 0, 'title' => 'Not mounted', 'perms_everybody' => 17]);
        $pages->insert('pages', ['uid' => 30, 'pid' => 0, 'title' => 'Hidden page', 'perms_everybody' => 1, 'hidden' => 1]);
        // PAGE_SHOW only, deliberately no CONTENT_EDIT: proves tables_modify
        // plus merely SEEING a page is not enough to earn an edit link.
        $pages->insert('pages', ['uid' => 70, 'pid' => 0, 'title' => 'Sysfolder, view-only', 'perms_everybody' => 1]);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');

        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Reused fragment', 'prompt' => 'Shared tone.', 'scope' => 'all']);
        $this->reusedFragmentUid = (int)$fragments->lastInsertId();
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 10, 'parent_page' => 10, 'fragment' => $this->reusedFragmentUid]);
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 20, 'parent_page' => 20, 'fragment' => $this->reusedFragmentUid]);
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 30, 'parent_page' => 30, 'fragment' => $this->reusedFragmentUid]);

        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Unused fragment', 'prompt' => 'Nobody uses this yet.', 'scope' => 'all']);
        $this->unusedFragmentUid = (int)$fragments->lastInsertId();

        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 20, 'title' => 'Sysfolder fragment', 'prompt' => 'Lives in a restricted sysfolder.', 'scope' => 'all']);
        $this->sysfolderFragmentUid = (int)$fragments->lastInsertId();

        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 70, 'title' => 'View-only sysfolder fragment', 'prompt' => 'Lives on a page the editor can only see.', 'scope' => 'all']);
        $this->viewOnlySysfolderFragmentUid = (int)$fragments->lastInsertId();

        $beGroups = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $beGroups->insert('be_groups', ['uid' => 60, 'title' => 'Fragment editors', 'tables_modify' => 'tx_aim_prompt_fragment']);
        // Browse-only: tables_select but not tables_modify - the "sees but
        // can't edit" role these tests exercise (see anEditLinkIsShownOnlyToAUserAllowedToModifyFragments).
        // Without this grant, the module has nothing to show this user at
        // all (see hasFragmentReadAccess in the controller), which is a
        // different, coarser permission dimension than tables_modify.
        $beGroups->insert('be_groups', ['uid' => 61, 'title' => 'Fragment viewers', 'tables_select' => 'tx_aim_prompt_fragment']);

        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $beUsers->insert('be_users', ['uid' => 50, 'username' => 'restricted-editor', 'admin' => 0, 'db_mountpoints' => '10', 'usergroup' => '61']);
        $beUsers->insert('be_users', ['uid' => 51, 'username' => 'fragment-editor', 'admin' => 0, 'db_mountpoints' => '20,70', 'usergroup' => '60']);
        $beUsers->insert('be_users', ['uid' => 52, 'username' => 'no-fragment-access', 'admin' => 0, 'db_mountpoints' => '10']);
    }

    #[Test]
    public function listsEveryGlobalFragmentForAnAdmin(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('Reused fragment', $body);
        self::assertStringContainsString('Unused fragment', $body);
        self::assertStringContainsString('Sysfolder fragment', $body);
    }

    /**
     * Regression test: the "New fragment" doc-header button was never
     * covered by any test, unlike every other permission gate this view
     * adds (see e.g. anEditLinkIsShownOnlyToAUserAllowedToModifyFragments
     * below).
     *
     * Deliberately its own test method, not paired with the admin-absence
     * check below in one method: DocHeaderComponent is
     * #[Autoconfigure(public: true)] in TYPO3 core, so
     * GeneralUtility::makeInstance() (used by ModuleTemplate's own
     * constructor) returns the SAME container-shared instance for every
     * ModuleTemplate built within one test method - two sequential
     * $this->library() calls in one method would accumulate buttons onto
     * the same ButtonBar instead of getting an independent one each time,
     * which isn't a real production bug (a real request never shares a
     * container across requests), but would be a false positive here.
     */
    #[Test]
    public function theNewFragmentButtonIsShownToAnAdmin(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('edit%5Btx_aim_prompt_fragment%5D%5B0%5D=new', $body);
    }

    /**
     * Regression test: see theNewFragmentButtonIsShownToAnAdmin()'s
     * identical rationale for why this is a separate test method.
     * Deliberately checked against the fragment-editor (uid 51), not the
     * no-fragment-access user (uid 52): a brand-new library entry isn't
     * tied to any one page's own permissions yet, so with no page selected
     * the only sensible target left is pid=0 - a true global library entry,
     * which DataHandler only permits an admin to write - so the button
     * stays hidden here even though 51 already has tables_modify. See
     * theNewFragmentButtonTargetsTheSelectedPageForANonAdminAllowedToEditThere()
     * below for the same user WITH a page selected, where it does show.
     */
    #[Test]
    public function theNewFragmentButtonIsHiddenFromANonAdminWithNoPageSelected(): void
    {
        $this->setUpBackendUser(51);

        $body = (string)$this->library()->getBody();

        self::assertStringNotContainsString('edit%5Btx_aim_prompt_fragment%5D%5B0%5D=new', $body);
    }

    /**
     * Regression test: "New fragment" used to be admin-only unconditionally,
     * even though a sysfolder-stored fragment (pid > 0) is ordinary
     * DataHandler-writable for any user with tables_modify + CONTENT_EDIT
     * on that page - the exact same rule an existing row at that pid is
     * already held to (see canEditFragmentRow()). With a page selected in
     * the tree, the button now targets that page's own pid instead of the
     * admin-only pid=0, so a non-admin fragment-editor can use it too. Page
     * 20 here is the fragment-editor's own webmount and has CONTENT_EDIT
     * (perms_everybody=17, see setUp()).
     */
    #[Test]
    public function theNewFragmentButtonTargetsTheSelectedPageForANonAdminAllowedToEditThere(): void
    {
        $this->setUpBackendUser(51);

        $body = (string)$this->library(['id' => '20'])->getBody();

        self::assertStringContainsString('edit%5Btx_aim_prompt_fragment%5D%5B20%5D=new', $body);
    }

    /**
     * Regression test: the selected-page target above must still respect
     * CONTENT_EDIT, not just PAGE_SHOW - page 70 is one of the
     * fragment-editor's own webmounts (so it's a real, reachable
     * selection), but perms_everybody there only grants PAGE_SHOW (see
     * setUp()), the same "sees but can't edit" distinction
     * anEditLinkIsShownOnlyToAUserAllowedToModifyFragments() already covers
     * for an existing row.
     */
    #[Test]
    public function theNewFragmentButtonIsHiddenWhenTheSelectedPageIsNotContentEditable(): void
    {
        $this->setUpBackendUser(51);

        $body = (string)$this->library(['id' => '70'])->getBody();

        self::assertStringNotContainsString('edit%5Btx_aim_prompt_fragment%5D%5B70%5D=new', $body);
    }

    /**
     * Regression test: this listing used to render a cropped preview of
     * each fragment's own prompt content directly beneath its title -
     * redundant at best, and outright confusing whenever a fragment's
     * title happens to echo its own prompt wording. The row shows only the
     * title; the full prompt is one click away in the edit form.
     */
    #[Test]
    public function theListingShowsOnlyTheFragmentTitleNotItsPromptContent(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('Reused fragment', $body);
        self::assertStringNotContainsString('Shared tone.', $body, "The fragment's own prompt content must not be rendered in the listing.");
    }

    /**
     * Regression test: hide/unhide and delete are the same "may this user
     * modify this specific fragment row" question the edit link already
     * answers via canEditFragmentRow() - DataHandler's own deleteRecord()
     * applies the identical pid<=0-is-admin-only / CONTENT_EDIT-on-the-
     * containing-page rule to a delete as it does to an update (see
     * DataHandler::deleteRecord()'s VirtualRecord::RootPage branch), so
     * reusing {fragment.canEdit} for all three actions is not a
     * simplification of a stricter server-side rule, it's the exact same
     * rule. A user without tables_modify must see none of the three.
     */
    #[Test]
    public function hideUnhideAndDeleteActionsAreShownOnlyToAUserAllowedToModifyFragments(): void
    {
        $this->setUpBackendUser(1);
        $adminBody = (string)$this->library()->getBody();
        self::assertMatchesRegularExpression('/data%5Btx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D%5Bhidden%5D=1/', $adminBody);
        self::assertMatchesRegularExpression('/cmd%5Btx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D%5Bdelete%5D=1/', $adminBody);

        $this->setUpBackendUser(50);
        $restrictedBody = (string)$this->library()->getBody();
        self::assertStringContainsString('Reused fragment', $restrictedBody);
        self::assertDoesNotMatchRegularExpression('/tx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D%5Bhidden%5D/', $restrictedBody);
        self::assertDoesNotMatchRegularExpression('/tx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D%5Bdelete%5D/', $restrictedBody);
    }

    /**
     * Regression test: the Library listing used to apply the default
     * restrictions (which include HiddenRestriction), silently excluding a
     * hidden fragment. That made the new "Hide" action a dead end - once
     * hidden, a fragment would vanish from the one screen that could
     * unhide it again. It must stay listed, and its own action switches
     * from "Hide" to "Unhide".
     */
    #[Test]
    public function aHiddenFragmentStaysListedSoItCanBeUnhidden(): void
    {
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Hidden global fragment', 'prompt' => 'Tone.', 'scope' => 'all', 'hidden' => 1]);
        $hiddenFragmentUid = (int)$fragments->lastInsertId();

        $this->setUpBackendUser(1);
        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('Hidden global fragment', $body);
        self::assertMatchesRegularExpression('/data%5Btx_aim_prompt_fragment%5D%5B' . $hiddenFragmentUid . '%5D%5Bhidden%5D=0/', $body, 'A hidden fragment must offer "unhide" (hidden=0), not "hide" (hidden=1).');
    }

    /**
     * Regression test: this module is only gated on 'access' => 'user' at
     * the module-menu level (which module an admin can grant a group at
     * all), with no further check that the granting group also intended
     * read access to fragment CONTENT specifically. Since this custom
     * listing builds its own Doctrine queries rather than going through
     * DataHandler/the standard record list (which would enforce
     * tables_select automatically), any backend user handed access to this
     * module - a common, low-friction grant - could otherwise read every
     * global fragment's full prompt text, including ones on pages/sites
     * they have no other access to at all.
     */
    #[Test]
    public function aUserWithoutTablesSelectSeesNoFragmentContentAtAll(): void
    {
        $this->setUpBackendUser(52);

        $body = (string)$this->library()->getBody();

        self::assertStringNotContainsString('Reused fragment', $body);
        self::assertStringNotContainsString('Unused fragment', $body);
        self::assertStringNotContainsString('Sysfolder fragment', $body);
        self::assertStringContainsString("doesn&#039;t grant read access", $body);
    }

    #[Test]
    public function showsTheUsageCountForAReusedFragmentAndANoneMessageForAnUnusedOne(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('3 page(s)', $body);
        self::assertStringContainsString('Not assigned anywhere yet', $body);
    }

    #[Test]
    public function theUsageCountAndListIncludeAHiddenPageInsteadOfSilentlyDroppingIt(): void
    {
        // Regression test: a fragment still assigned to a disabled page is
        // genuinely still "used" by it. Silently excluding hidden pages
        // from the join would understate the count and could even render
        // the false "Not assigned anywhere yet" message for a fragment
        // that actually is assigned somewhere - misleading in a view whose
        // entire job is reporting where a fragment is actually used.
        $this->setUpBackendUser(1);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('Hidden page', $body);
        self::assertMatchesRegularExpression('/<a href="[^"]*"[^>]*>Hidden page<\/a>\s*<span class="badge badge-warning">Hidden<\/span>/', $body);
    }

    /**
     * Regression test, end-to-end: the Library sub-action used to have no
     * page-tree filtering at all (the standing complaint the merge into
     * Prompt Management fixes) - selecting a page in the shared tree must
     * actually scope this listing. "Sysfolder fragment" (pid=20) has no
     * assignment anywhere in setUp() - it was only ever visible via the
     * unrelated access-control check on its own storage pid, never via any
     * real usage - so once tree-scoped to page 10's subtree it must
     * disappear, while the global "Reused fragment"/"Unused fragment"
     * (exempt from tree filtering, matching their existing exemption from
     * the access check) stay visible.
     */
    #[Test]
    public function selectingAPageInTheTreeScopesTheLibraryListingToFragmentsUsedInThatSubtree(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->library(['id' => '10'])->getBody();

        self::assertStringContainsString('Reused fragment', $body);
        self::assertStringContainsString('Unused fragment', $body);
        self::assertStringNotContainsString('Sysfolder fragment', $body);
    }

    /**
     * Regression test: a global (pid=0) fragment is exempt from the
     * tree-selection usage filter (it always appears, whether or not it's
     * actually assigned near the selected page), so it must always carry a
     * "Global" badge, both with and without an active subtree selection,
     * to make clear it's shown unconditionally rather than because it's
     * specifically used near the selected page.
     */
    #[Test]
    public function aGlobalFragmentIsAlwaysBadgedRegardlessOfTreeSelection(): void
    {
        $this->setUpBackendUser(1);

        $scopedBody = (string)$this->library(['id' => '10'])->getBody();
        self::assertMatchesRegularExpression(
            '/Reused fragment[\s\S]{0,200}aim-chip" data-tone="info"/',
            $scopedBody,
            'A global fragment must be badged while a tree selection is active.',
        );

        $unscopedBody = (string)$this->library()->getBody();
        self::assertMatchesRegularExpression(
            '/Reused fragment[\s\S]{0,200}aim-chip" data-tone="info"/',
            $unscopedBody,
            'A global fragment must be badged even without a tree selection.',
        );
    }

    /**
     * Regression test: the tree-selection filter above (`id`) is a raw,
     * unvalidated request parameter - PageTreeResolver::resolveSubtree()
     * applies no permission check of its own. Without intersecting it with
     * the user's own $accessiblePageIds first (the same way the Pages
     * sub-action's identical subtree narrowing already does), a restricted
     * user could pass an `id` for a page they cannot see and learn, via
     * whether a fragment they CAN already see shows up as "used" there,
     * something about a page outside their own reach. This fragment is
     * stored at pid=10 (inside the restricted user's own mount, so it
     * legitimately passes the unrelated storage-pid access check and would
     * otherwise be visible), but only ever assigned to page 20 (outside
     * their mount) - so once tree-scoped to page 20, it must disappear for
     * this user, even though page 20 genuinely has a real assignment there.
     */
    #[Test]
    public function theUsageFilterIsScopedToPagesTheRequestingUserCanActuallySee(): void
    {
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 10, 'title' => 'Storage-visible, used elsewhere', 'prompt' => 'Tone.', 'scope' => 'all']);
        $fragmentUid = (int)$fragments->lastInsertId();
        $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment')
            ->insert('tx_aim_page_prompt_fragment', ['pid' => 20, 'parent_page' => 20, 'fragment' => $fragmentUid]);

        $this->setUpBackendUser(50);
        $body = (string)$this->library(['id' => '20'])->getBody();

        self::assertStringNotContainsString('Storage-visible, used elsewhere', $body);
    }

    #[Test]
    public function aRestrictedUserDoesNotSeeAFragmentLivingInASysfolderTheyCannotAccess(): void
    {
        $this->setUpBackendUser(50);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('Reused fragment', $body);
        self::assertStringNotContainsString('Sysfolder fragment', $body);
    }

    #[Test]
    public function aRestrictedUsersUsageCountForAReusedFragmentOnlyReflectsPagesTheyCanSee(): void
    {
        // The fragment is assigned to both page 10 (mounted) and page 20
        // (not mounted). The restricted user must see "1 page(s)", not
        // "2 page(s)", which would leak that an inaccessible page also uses it.
        $this->setUpBackendUser(50);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('1 page(s)', $body);
        self::assertStringNotContainsString('2 page(s)', $body);
    }

    #[Test]
    public function theUsageListNamesTheActualPagesForAnAdmin(): void
    {
        // Regression coverage for the inline usage list itself, not just
        // the count: an admin expanding "3 page(s)" must see all three real
        // page titles, each linking straight to that page's own AI tab.
        $this->setUpBackendUser(1);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('Mounted', $body);
        self::assertStringContainsString('Not mounted', $body);
        self::assertMatchesRegularExpression('/<a href="[^"]*"[^>]*>Mounted<\/a>/', $body);
        self::assertMatchesRegularExpression('/<a href="[^"]*"[^>]*>Not mounted<\/a>/', $body);
    }

    #[Test]
    public function aRestrictedUsersUsageListOnlyNamesPagesTheyCanSee(): void
    {
        // Same leak check as the count above, applied to the actual list
        // content: the restricted user's expanded list must name "Mounted"
        // but never "Not mounted", the page outside their webmount.
        $this->setUpBackendUser(50);

        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('Mounted', $body);
        self::assertStringNotContainsString('Not mounted', $body);
    }

    #[Test]
    public function usagePageRowsRenderAPageIconWithAContextMenuTrigger(): void
    {
        // Regression coverage for the switch from a breadcrumb path to a
        // plain icon + context menu (matching how the sibling Pages module
        // already lists pages): each usage row must carry a real
        // data-contextmenu-uid for its own page, not just a generic trigger.
        $this->setUpBackendUser(1);

        $body = (string)$this->library()->getBody();

        self::assertMatchesRegularExpression('/data-contextmenu-table="pages"[^>]*data-contextmenu-uid="10"/', $body);
    }

    /**
     * Regression test: the "… and N more" row (shown once a fragment's
     * usage exceeds MAX_USAGE_PAGES_SHOWN) had `colspan="3"` on its second
     * cell, one column short of the 4 remaining columns after col-icon
     * (title/capabilities/usage/col-control - matching the colspan="2" +
     * empty <td> + col-control an ordinary usage-page row spans across
     * those same 4). That misaligned the row against every other row in
     * the table.
     */
    #[Test]
    public function theAndMoreRowSpansAllRemainingColumns(): void
    {
        $this->setUpBackendUser(1);
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Widely-used fragment', 'prompt' => 'Tone.', 'scope' => 'all']);
        $fragmentUid = (int)$fragments->lastInsertId();
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        for ($i = 1; $i <= 26; $i++) {
            $pageUid = 1000 + $i;
            $pages->insert('pages', ['uid' => $pageUid, 'pid' => 0, 'title' => 'Widely-used page ' . $i]);
            $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => $pageUid, 'parent_page' => $pageUid, 'fragment' => $fragmentUid]);
        }

        $body = (string)$this->library()->getBody();

        self::assertMatchesRegularExpression(
            '/and 1 more/',
            $body,
            'Expected exactly 1 page beyond the 25-page cap to be reported as truncated.',
        );
        self::assertMatchesRegularExpression(
            '/<td class="col-icon"><\/td>[\s\S]*?<td colspan="4" class="text-body-secondary">/',
            $body,
            'The truncation row must span all 4 remaining columns (5 total with col-icon), matching every other row.',
        );
    }

    /**
     * Regression test: every fragment edit link in this view used to render
     * unconditionally, unlike the sibling page-column links (guarded by
     * {page.canEdit}). A user without `tables_modify` for
     * tx_aim_prompt_fragment (a table-level permission, not per-record)
     * would follow it straight into an "access denied" dead end.
     *
     * Covers all three cases, not just admin-vs-permissionless: a plain
     * non-admin user (uid 50, no usergroup at all) is denied, but a
     * non-admin user whose group explicitly grants `tables_modify` for
     * tx_aim_prompt_fragment (uid 51, mounted on page 20) is granted the
     * link for the sysfolder-stored fragment they can also see - proving
     * this checks that specific permission rather than merely isAdmin() or
     * the wrong table.
     */
    #[Test]
    public function anEditLinkIsShownOnlyToAUserAllowedToModifyFragments(): void
    {
        $this->setUpBackendUser(1);
        $adminBody = (string)$this->library()->getBody();
        self::assertMatchesRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D=edit/', $adminBody);

        $this->setUpBackendUser(50);
        $restrictedBody = (string)$this->library()->getBody();
        self::assertStringContainsString('Reused fragment', $restrictedBody);
        self::assertDoesNotMatchRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D=edit/', $restrictedBody);

        $this->setUpBackendUser(51);
        $fragmentEditorBody = (string)$this->library()->getBody();
        self::assertMatchesRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $this->sysfolderFragmentUid . '%5D=edit/', $fragmentEditorBody);
    }

    /**
     * Regression test: tables_modify for tx_aim_prompt_fragment is
     * necessary but not sufficient for a pid=0 (global library) fragment
     * specifically - TCA doesn't set security.ignoreRootLevelRestriction on
     * this table, so DataHandler additionally requires the user to be an
     * admin before it will save ANY record at pid=0
     * (VirtualRecord::RootPage), regardless of table-level grants. A
     * non-admin fragment-editor (uid 51, granted tables_modify) must NOT
     * see a working-looking edit link on a global fragment that would fail
     * at save time - only an admin bypasses this.
     */
    #[Test]
    public function anEditLinkForAGlobalPidZeroFragmentIsShownOnlyToAnAdmin(): void
    {
        $this->setUpBackendUser(51);
        $fragmentEditorBody = (string)$this->library()->getBody();
        self::assertStringContainsString('Reused fragment', $fragmentEditorBody);
        self::assertDoesNotMatchRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D=edit/', $fragmentEditorBody);

        $this->setUpBackendUser(1);
        $adminBody = (string)$this->library()->getBody();
        self::assertMatchesRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $this->reusedFragmentUid . '%5D=edit/', $adminBody);
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
     * "Sysfolder fragment" on page 20 (which grants both).
     */
    #[Test]
    public function anEditLinkIsNotShownForAFragmentOnAPageTheUserCanSeeButNotContentEdit(): void
    {
        $this->setUpBackendUser(51);
        $body = (string)$this->library()->getBody();

        self::assertStringContainsString('View-only sysfolder fragment', $body);
        self::assertDoesNotMatchRegularExpression('/edit%5Btx_aim_prompt_fragment%5D%5B' . $this->viewOnlySysfolderFragmentUid . '%5D=edit/', $body);
    }

    /**
     * Regression test for the actual point of wiring 'view' up as a
     * moduleData property: previously, a request with no 'view' at all
     * (exactly what the page tree's own click handler sends, see
     * prompt-preview.js) could only be restored to the last-active view
     * client-side, via localStorage, which was genuinely untestable at this
     * level.
     * Now BackendModuleValidator persists/restores it server-side before
     * the controller ever runs, so a view-less request correctly falls
     * back to whatever was last persisted for this user, which this test
     * simulates directly (see library()'s own comment on why).
     */
    #[Test]
    public function fallsBackToThePersistedViewWhenTheRequestCarriesNoExplicitViewParameter(): void
    {
        $this->setUpBackendUser(1);
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
        $request = new ServerRequest($url, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('route', new Route('/module/admin/aim/prompt-management', ['packageName' => 'b13/aim']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $request = $request->withQueryParams([]);
        $request = $request->withAttribute('moduleData', new ModuleData('aim_prompt_management', ['view' => 'library']));

        $body = (string)$controller->overviewAction($request)->getBody();

        // "Unused fragment" (see class docblock) has no page assignments at
        // all, so it can only ever appear in the Library listing (every
        // fragment), never in the Pages one (only pages that use one),
        // making it a clean discriminator between the two, unlike a
        // fragment's title that could also leak into the Pages listing via
        // its own per-page "N fragment(s)" disclosure.
        self::assertStringContainsString('Unused fragment', $body, 'The Library listing must render even though this request carried no explicit view parameter.');
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function library(array $queryParams = []): ResponseInterface
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
        // The Library sub-action of the merged aim_prompt_management module
        // (see PromptManagementController::overviewAction()'s 'view' branch),
        // not a separate route/action any more.
        $queryParams = array_merge(['view' => 'library'], $queryParams);
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

    /**
     * Regression test: paginationBaseUrl used to pass the demand's own
     * scope/search straight through as flat query params (`?scope=...`
     * instead of `?demand[scope]=...`, the only shape
     * PagePromptFragmentDemand::fromRequest() actually reads) and never
     * included orderField/orderDirection at all - so filtering or sorting,
     * then clicking "next page", silently reset both back to their
     * defaults. Needs > limit (15) matching fragments so pagination
     * actually renders at all (Pagination.html only renders when
     * numberOfPages > 1).
     */
    #[Test]
    public function paginationLinksPreserveTheActiveFilterAndSort(): void
    {
        $this->setUpBackendUser(1);
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        for ($i = 1; $i <= 20; $i++) {
            $fragments->insert('tx_aim_prompt_fragment', [
                'pid' => 0,
                'title' => 'Paginated fragment ' . $i,
                'prompt' => 'searchable-marker',
                'scope' => 'text',
            ]);
        }

        $body = (string)$this->library([
            'demand' => ['scope' => 'text', 'search' => 'searchable-marker'],
            'orderField' => 'title',
            'orderDirection' => 'desc',
        ])->getBody();

        // Scoped specifically to the pagination nav's own data-navigate-value
        // (built from paginationBaseUrl), not just "anywhere in the body" -
        // the sort-column header links (SortUrlBuilder::build(), a
        // different, already-correct code path) legitimately carry the same
        // demand[...]/orderField/orderDirection substrings for their own,
        // unrelated hrefs, which would make a body-wide assertion pass even
        // without this fix (confirmed: it did, until this test was scoped).
        self::assertMatchesRegularExpression('/data-navigate-value="[^"]*"/', $body, 'Pagination did not render at all - fixture must yield > 15 matching fragments.');
        preg_match('/data-navigate-value="([^"]*)"/', $body, $matches);
        $paginationUrl = $matches[1] ?? '';

        self::assertStringContainsString('demand%5Bscope%5D=text', $paginationUrl);
        self::assertStringContainsString('demand%5Bsearch%5D=searchable-marker', $paginationUrl);
        self::assertStringContainsString('orderField=title', $paginationUrl);
        self::assertStringContainsString('orderDirection=desc', $paginationUrl);
    }
}
