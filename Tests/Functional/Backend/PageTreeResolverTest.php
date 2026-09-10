<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Backend;

use B13\Aim\Backend\PageTreeResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Each group of tests below seeds its own page tree via a dedicated,
 * explicitly-called helper rather than a single shared setUp(): the three
 * methods under test each need a differently-shaped tree, and several of
 * them reuse the same low page uids (1, 2, 3, 10), which would collide if
 * inserted together in one fixture.
 */
final class PageTreeResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    // --- resolveAccessiblePageIds() ---
    //
    // Two independent, equally publicly-readable (perms_everybody grants
    // PAGE_SHOW on both) subtrees; the only difference between them is
    // which one the test backend user is webmounted to. This isolates the
    // thing this method actually exists to test: that webmounts, not just
    // the page-level permission bits, gate what a flat/cross-tree listing
    // may show a non-admin user.
    //
    //   100 "Mounted root"        (perms_everybody grants PAGE_SHOW)
    //     101 "Mounted child"     (perms_everybody grants PAGE_SHOW)
    //   200 "Unmounted root"      (perms_everybody grants PAGE_SHOW too)
    //     201 "Unmounted child"

    #[Test]
    public function resolveAccessiblePageIdsReturnsNullForAnAdminEvenWithNoWebmounts(): void
    {
        $this->seedAccessibilityFixture();
        $this->insertBackendUser(60, ['admin' => 1, 'db_mountpoints' => '']);
        $backendUser = $this->setUpBackendUser(60);

        self::assertNull($this->resolver()->resolveAccessiblePageIds($backendUser));
    }

    #[Test]
    public function resolveAccessiblePageIdsResolvesOnlyTheMountedSubtreeNotTheUnmountedOne(): void
    {
        $this->seedAccessibilityFixture();
        $backendUser = $this->setUpBackendUser(50);

        $resolved = $this->resolver()->resolveAccessiblePageIds($backendUser);

        self::assertNotNull($resolved);
        sort($resolved);
        self::assertSame([100, 101], $resolved);
    }

    // --- resolveSubtree() ---
    //
    //   1 "Root"
    //     2 "Child"
    //       3 "Grandchild"
    //   10 "Unrelated sibling root"
    //     11 "Unrelated sibling child"

    #[Test]
    public function resolveSubtreeOnTheRootReturnsTheEntireSubtree(): void
    {
        $this->seedSubtreeFixture();
        $resolved = $this->resolver()->resolveSubtree(1);
        sort($resolved);

        self::assertSame([1, 2, 3], $resolved);
    }

    #[Test]
    public function resolveSubtreeOnAnIntermediatePageExcludesItsAncestorAndUnrelatedBranches(): void
    {
        $this->seedSubtreeFixture();
        $resolved = $this->resolver()->resolveSubtree(2);
        sort($resolved);

        self::assertSame([2, 3], $resolved);
    }

    #[Test]
    public function resolveSubtreeOnALeafPageReturnsOnlyItself(): void
    {
        $this->seedSubtreeFixture();
        self::assertSame([3], $this->resolver()->resolveSubtree(3));
    }

    // --- resolveBoundedSlice() ---
    //
    //   1 "Root"
    //     2 "Child A"
    //       4 "Grandchild A1"
    //     3 "Child B"
    //       5 "Grandchild B1"
    //   10 "Unrelated sibling root"

    #[Test]
    public function resolveBoundedSliceAtDepthZeroReturnsOnlyTheRootPage(): void
    {
        $this->seedBoundedSliceFixture();
        self::assertSame([1], $this->resolver()->resolveBoundedSlice(1, 0, 100));
    }

    #[Test]
    public function resolveBoundedSliceAtDepthOneIncludesDirectChildrenButNotGrandchildren(): void
    {
        $this->seedBoundedSliceFixture();
        $result = $this->resolver()->resolveBoundedSlice(1, 1, 100);
        sort($result);

        self::assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function resolveBoundedSliceAtDepthTwoIncludesGrandchildrenButNeverTheUnrelatedSibling(): void
    {
        $this->seedBoundedSliceFixture();
        $result = $this->resolver()->resolveBoundedSlice(1, 2, 100);
        sort($result);

        self::assertSame([1, 2, 3, 4, 5], $result);
        self::assertNotContains(10, $result);
    }

    #[Test]
    public function resolveBoundedSliceMaxPagesCapsTheResultRegardlessOfHowMuchTheTreeContains(): void
    {
        $this->seedBoundedSliceFixture();
        $result = $this->resolver()->resolveBoundedSlice(1, 2, 2);

        self::assertCount(2, $result);
        // The root page must always be the first entry, even under a cap.
        self::assertSame(1, $result[0]);
    }

    // --- visibility: the module filter and the crawl want opposite things ---
    //
    //   1 "Root"
    //     2 "Visible child"
    //     3 "Hidden child"          (hidden = 1)
    //     4 "Storage"               (doktype = 254 sysfolder)
    //       5 "Below storage"
    //     6 "Members only"          (fe_group = 2)

    private function seedVisibilityFixture(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root']);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Visible child']);
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'Hidden child', 'hidden' => 1]);
        $pages->insert('pages', ['uid' => 4, 'pid' => 1, 'title' => 'Storage', 'doktype' => 254]);
        $pages->insert('pages', ['uid' => 5, 'pid' => 4, 'title' => 'Below storage']);
        $pages->insert('pages', ['uid' => 6, 'pid' => 1, 'title' => 'Members only', 'fe_group' => '2']);
    }

    /**
     * The module's tree filter is an editor-facing listing, so it must not hide
     * anything: a sysfolder is a common place to keep fragments, and dropping
     * it took everything beneath it too.
     */
    #[Test]
    public function resolveSubtreeKeepsHiddenPagesSysfoldersAndWhatIsBelowThem(): void
    {
        $this->seedVisibilityFixture();

        $ids = $this->resolver()->resolveSubtree(1);
        sort($ids);

        self::assertSame([1, 2, 3, 4, 5, 6], $ids, 'The module filter dropped a page an editor can see.');
    }

    /**
     * The crawl sends page text to an AI provider, so it is limited to what a
     * visitor could open. fe_group sits on the page here, not on its content.
     */
    #[Test]
    public function resolveBoundedSliceSkipsWhatAVisitorCouldNotOpen(): void
    {
        $this->seedVisibilityFixture();

        $ids = $this->resolver()->resolveBoundedSlice(1, 99, 100);

        self::assertSame([1, 2], $ids, 'A hidden page, a sysfolder or a members-only page was crawled.');
    }

    /**
     * Naming a page with --page must not be a way around the same rules.
     */
    #[Test]
    public function resolveBoundedSliceDoesNotCrawlAnInvisibleRootJustBecauseItWasNamed(): void
    {
        $this->seedVisibilityFixture();

        self::assertSame([], $this->resolver()->resolveBoundedSlice(3, 99, 100), 'A hidden root was crawled.');
        self::assertSame([], $this->resolver()->resolveBoundedSlice(6, 99, 100), 'A members-only root was crawled.');
    }

    private function seedAccessibilityFixture(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 100, 'pid' => 0, 'title' => 'Mounted root', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 101, 'pid' => 100, 'title' => 'Mounted child', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 200, 'pid' => 0, 'title' => 'Unmounted root', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 201, 'pid' => 200, 'title' => 'Unmounted child', 'perms_everybody' => 1]);

        $this->insertBackendUser(50, [
            'username' => 'restricted-editor',
            'admin' => 0,
            'db_mountpoints' => '100',
        ]);
    }

    private function seedSubtreeFixture(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root']);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Child']);
        $pages->insert('pages', ['uid' => 3, 'pid' => 2, 'title' => 'Grandchild']);
        $pages->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Unrelated sibling root']);
        $pages->insert('pages', ['uid' => 11, 'pid' => 10, 'title' => 'Unrelated sibling child']);
    }

    private function seedBoundedSliceFixture(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root']);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Child A']);
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'Child B']);
        $pages->insert('pages', ['uid' => 4, 'pid' => 2, 'title' => 'Grandchild A1']);
        $pages->insert('pages', ['uid' => 5, 'pid' => 3, 'title' => 'Grandchild B1']);
        $pages->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Unrelated sibling root']);
    }

    private function insertBackendUser(int $uid, array $overrides): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', array_merge([
            'uid' => $uid,
            'username' => 'user-' . $uid,
        ], $overrides));
    }

    private function resolver(): PageTreeResolver
    {
        return $this->get(PageTreeResolver::class);
    }
}
