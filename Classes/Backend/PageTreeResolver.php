<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend;

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Everything in AiM that turns a starting point in the page tree into a
 * flat list<int> of page ids, for three distinct callers:
 *
 *  - resolveAccessiblePageIds(): which pages a backend user is actually
 *    allowed to see, for the Prompt Preview module's flat, cross-tree
 *    listing (not a page-tree-navigation context where a starting page is
 *    already given).
 *  - resolveSubtree(): a page and every descendant, for module's
 *    "selected page and down" tree-filter scope.
 *  - resolveBoundedSlice(): a root page and a capped, breadth-first slice
 *    of its subpages, for aim:calibrateVoice, which needs "a representative
 *    sample of this site" rather than every descendant.
 *
 * Each method gets its own fresh PageTreeRepository instance rather than a
 * shared, constructor-injected on.
 */
final class PageTreeResolver
{
    private const RECURSIVE_PAGE_LEVEL = 99;

    /**
     * @return list<int>|null null means "no restriction" (admin). An empty list means "no accessible pages".
     */
    public function resolveAccessiblePageIds(BackendUserAuthentication $backendUser): ?array
    {
        if ($backendUser->isAdmin()) {
            return null;
        }

        $mounts = $backendUser->getWebmounts();
        if ($mounts === []) {
            return [];
        }

        $pageTreeRepository = $this->freshRepository();
        $pageTreeRepository->setAdditionalWhereClause($backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        $pageIds = $mounts;
        foreach ($pageTreeRepository->getFlattenedPages($mounts, self::RECURSIVE_PAGE_LEVEL) as $page) {
            $pageIds[] = (int)$page['uid'];
        }

        return array_values(array_unique($pageIds));
    }

    /**
     * @return list<int> the given page id itself, plus every descendant
     */
    public function resolveSubtree(int $pageId): array
    {
        return $this->resolveBoundedSlice($pageId, self::RECURSIVE_PAGE_LEVEL, PHP_INT_MAX);
    }

    /**
     * @return list<int> rootPageId first, then descendants breadth-first,
     *         limited to $maxDepth levels below the root and $maxPages
     *         pages total
     */
    public function resolveBoundedSlice(int $rootPageId, int $maxDepth, int $maxPages): array
    {
        $pageIds = [$rootPageId];
        foreach ($this->freshRepository()->getFlattenedPages([$rootPageId], max(0, $maxDepth)) as $page) {
            $pageIds[] = (int)$page['uid'];
        }

        $pageIds = array_values(array_unique($pageIds));

        return array_slice($pageIds, 0, max(1, $maxPages));
    }

    private function freshRepository(): PageTreeRepository
    {
        return GeneralUtility::makeInstance(PageTreeRepository::class);
    }
}
