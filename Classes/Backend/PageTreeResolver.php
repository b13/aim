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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
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
 * shared, constructor-injected one.
 */
final class PageTreeResolver
{
    private const RECURSIVE_PAGE_LEVEL = 99;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @return list<int>|null null means "no restriction" (admin). An empty list means "no accessible pages".
     */
    public function resolveAccessiblePageIds(BackendUserAuthentication $backendUser): ?array
    {
        if ($backendUser->isAdmin()) {
            return null;
        }

        $mounts = method_exists($backendUser, 'getWebmounts')
            ? $backendUser->getWebmounts()
            : array_map('intval', $backendUser->returnWebmounts());
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
        $repository = $this->freshRepository();

        $pageIds = [$pageId];
        foreach ($repository->getFlattenedPages([$pageId], self::RECURSIVE_PAGE_LEVEL) as $page) {
            $pageIds[] = (int)$page['uid'];
        }

        return array_values(array_unique($pageIds));
    }

    /**
     * @return list<int> rootPageId first, then descendants breadth-first,
     *         limited to $maxDepth levels below the root and $maxPages
     *         pages total
     */
    public function resolveBoundedSlice(int $rootPageId, int $maxDepth, int $maxPages): array
    {
        // This feeds a crawl whose text is sent to an AI provider, so it is
        // limited to what a visitor could see.
        $repository = $this->freshRepository();
        $repository->setAdditionalWhereClause($this->publiclyVisiblePagesClause());

        // The root is included so the traversal has a starting point, but it is
        // then filtered like every other page: seeding it unconditionally
        // crawled a hidden page, a sysfolder or a recycler whenever it was
        // named directly with --page.
        $pageIds = [$rootPageId];
        foreach ($repository->getFlattenedPages([$rootPageId], max(0, $maxDepth)) as $page) {
            $pageIds[] = (int)$page['uid'];
        }

        $pageIds = $this->keepPubliclyVisible(array_values(array_unique($pageIds)));

        return array_slice($pageIds, 0, max(1, $maxPages));
    }

    /**
     * Keeps only the pages an anonymous visitor could open, preserving order.
     *
     * The WHERE fragment below covers the enable fields and the doktype cap,
     * but it cannot express fe_group: that is a comma-separated list, and
     * getting the membership test right by hand is how this kind of check goes
     * wrong. Core's FrontendGroupRestriction already does it, so the collected
     * ids are put through it here. Group 0 plus -1 is what a visitor with no
     * login sees, -1 being the "hide at login" case.
     *
     * @param list<int> $pageIds
     * @return list<int>
     */
    private function keepPubliclyVisible(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()
            ->add(GeneralUtility::makeInstance(FrontendGroupRestriction::class, [0, -1]));

        $visible = $qb->select('uid')
            ->from('pages')
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->lt('doktype', $qb->createNamedParameter(
                    PageRepository::DOKTYPE_BE_USER_SECTION,
                    Connection::PARAM_INT,
                )),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $visible = array_map('intval', $visible);

        return array_values(array_filter($pageIds, static fn(int $uid): bool => in_array($uid, $visible, true)));
    }

    /**
     * Enable-fields plus a doktype cap as a WHERE fragment, which is the only
     * hook PageTreeRepository exposes.
     */
    private function publiclyVisiblePagesClause(): string
    {
        $enableColumns = $GLOBALS['TCA']['pages']['ctrl']['enablecolumns'] ?? [];
        $now = (int)($GLOBALS['SIM_ACCESS_TIME'] ?? time());

        $clauses = [];
        if (isset($enableColumns['disabled'])) {
            $clauses[] = 'pages.' . $enableColumns['disabled'] . ' = 0';
        }
        if (isset($enableColumns['starttime'])) {
            $clauses[] = '(pages.' . $enableColumns['starttime'] . ' = 0 OR pages.' . $enableColumns['starttime'] . ' <= ' . $now . ')';
        }
        if (isset($enableColumns['endtime'])) {
            $clauses[] = '(pages.' . $enableColumns['endtime'] . ' = 0 OR pages.' . $enableColumns['endtime'] . ' > ' . $now . ')';
        }
        $clauses[] = 'pages.doktype < ' . PageRepository::DOKTYPE_BE_USER_SECTION;

        return ' AND ' . implode(' AND ', $clauses);
    }

    private function freshRepository(): PageTreeRepository
    {
        return GeneralUtility::makeInstance(PageTreeRepository::class);
    }
}
