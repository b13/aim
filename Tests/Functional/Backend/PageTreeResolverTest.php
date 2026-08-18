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
