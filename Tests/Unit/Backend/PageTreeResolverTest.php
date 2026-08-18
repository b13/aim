<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Backend;

use B13\Aim\Backend\PageTreeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Covers only the two resolveAccessiblePageIds() branches that don't need a
 * real page tree: admin bypass and the empty-webmounts case. The real
 * "non-admin with a restricted subtree" resolution, and every case of
 * resolveSubtree()/resolveBoundedSlice(), need PageTreeRepository against a
 * real database and are covered by the functional test instead.
 */
final class PageTreeResolverTest extends TestCase
{
    #[Test]
    public function resolveAccessiblePageIdsReturnsNullForAnAdminMeaningNoRestriction(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->expects(self::never())->method('getWebmounts');

        self::assertNull((new PageTreeResolver())->resolveAccessiblePageIds($backendUser));
    }

    #[Test]
    public function resolveAccessiblePageIdsReturnsAnEmptyListRatherThanNullForANonAdminWithNoWebmounts(): void
    {
        // Empty and null must never be conflated: an empty list means "no
        // accessible pages," while null means "unrestricted." A bug that
        // returned null here would let a user with zero webmounts see
        // every page in the installation.
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getWebmounts')->willReturn([]);

        self::assertSame([], (new PageTreeResolver())->resolveAccessiblePageIds($backendUser));
    }
}
