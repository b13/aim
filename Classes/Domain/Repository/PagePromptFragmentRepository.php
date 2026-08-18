<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Domain\Repository;

use B13\Aim\Prompt\PromptFragmentScope;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Finds `pages` records that have at least one `tx_aim_prompt_fragment`child.
 */
class PagePromptFragmentRepository
{
    private const PAGES_TABLE = 'pages';
    private const FRAGMENT_TABLE = 'tx_aim_prompt_fragment';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int>|null $accessiblePageIds null = unrestricted (admin); [] must short-circuit to no results
     * @return list<array<string, mixed>>
     */
    public function findByDemand(PagePromptFragmentDemand $demand, ?array $accessiblePageIds): array
    {
        if ($accessiblePageIds === []) {
            return [];
        }
        $qb = $this->getQueryBuilderForDemand($demand, $accessiblePageIds);
        $qb->distinct()
            ->select(
                'pages.uid',
                'pages.pid',
                'pages.title',
                'pages.tx_aim_disable_inherited_fragments',
                'pages.doktype',
                'pages.is_siteroot',
                'pages.nav_hide',
                'pages.module',
                'pages.content_from_pid',
                'pages.hidden',
                'pages.starttime',
                'pages.endtime',
                'pages.fe_group',
                'pages.perms_userid',
                'pages.perms_groupid',
                'pages.perms_user',
                'pages.perms_group',
                'pages.perms_everybody',
            )
            ->orderBy('pages.' . $demand->getOrderField(), $demand->getOrderDirection())
            ->addOrderBy('pages.uid', 'asc')
            ->setMaxResults($demand->getLimit())
            ->setFirstResult($demand->getOffset());

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<int>|null $accessiblePageIds
     */
    public function countByDemand(PagePromptFragmentDemand $demand, ?array $accessiblePageIds): int
    {
        if ($accessiblePageIds === []) {
            return 0;
        }
        // The join underneath (same as findByDemand()) produces one row per
        // matching fragment, so a plain COUNT(*) would count fragments, not
        // pages. A page with 3 matching fragments would count as 3.
        // COUNT(DISTINCT pages.uid) is the correct "how many distinct pages
        // match" query.
        $qb = $this->getQueryBuilderForDemand($demand, $accessiblePageIds);
        // count() quotes its entire argument as a single identifier, so it
        // can't express "DISTINCT column", addSelectLiteral() with a raw
        // COUNT(DISTINCT ...) expression is this codebase's own established
        // way around that (see RequestLogRepository's aggregate queries).
        $qb->addSelectLiteral('COUNT(DISTINCT ' . $qb->quoteIdentifier('pages.uid') . ')');
        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * Adds `fragmentCount` and `scopes` (list<string>, distinct) to each
     * given page row, from a single follow-up query scoped to just those
     * page ids, not the whole matching set, so this never grows with
     * total fragment/page count, only with the current result page size.
     *
     * @param list<array<string, mixed>> $pageRows
     * @return list<array<string, mixed>>
     */
    public function enrichWithFragmentSummary(array $pageRows): array
    {
        if ($pageRows === []) {
            return [];
        }
        $pageIds = array_map(static fn(array $row): int => (int)$row['uid'], $pageRows);

        $qb = $this->connectionPool->getQueryBuilderForTable(self::FRAGMENT_TABLE);
        $rows = $qb->select('parent_page', 'scope')
            ->from(self::FRAGMENT_TABLE)
            ->where(
                $qb->expr()->in('parent_page', $qb->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $countByPage = [];
        $scopesByPage = [];
        foreach ($rows as $row) {
            $pageId = (int)$row['parent_page'];
            $countByPage[$pageId] = ($countByPage[$pageId] ?? 0) + 1;
            foreach (PromptFragmentScope::parseList((string)$row['scope']) as $scopeValue) {
                $scopesByPage[$pageId][$scopeValue] = true;
            }
        }

        return array_map(static function (array $pageRow) use ($countByPage, $scopesByPage): array {
            $pageId = (int)$pageRow['uid'];
            $pageRow['fragmentCount'] = $countByPage[$pageId] ?? 0;
            $pageRow['scopes'] = array_keys($scopesByPage[$pageId] ?? []);
            return $pageRow;
        }, $pageRows);
    }

    /**
     * Creates or updates THE single CLI-auto-detected fragment for a page
     * (marked via auto_generated=1). Upserts rather than appending a new
     * record every run, so re-running aim:calibrateVoice refreshes the
     * same fragment instead of piling up duplicates. An editor's own
     * hand-authored fragments (auto_generated=0, the default for anything
     * created through the normal edit form) are never matched or touched
     * by this, since the lookup is scoped to auto_generated=1.
     *
     * Bypasses DataHandler deliberately.
     */
    public function upsertAutoDetectedFragment(int $pageId, string $title, string $prompt, string $examples, array $scope): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::FRAGMENT_TABLE);
        $existingUid = $qb
            ->select('uid')
            ->from(self::FRAGMENT_TABLE)
            ->where(
                $qb->expr()->eq('parent_page', $qb->createNamedParameter($pageId, Connection::PARAM_INT)),
                $qb->expr()->eq('auto_generated', $qb->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        $values = [
            'title' => $title,
            'prompt' => $prompt,
            'examples' => $examples,
            'scope' => implode(',', $scope),
            'tstamp' => time(),
        ];

        $connection = $this->connectionPool->getConnectionForTable(self::FRAGMENT_TABLE);
        if ($existingUid !== false) {
            $connection->update(self::FRAGMENT_TABLE, $values, ['uid' => (int)$existingUid]);
            return (int)$existingUid;
        }

        $connection->insert(self::FRAGMENT_TABLE, [
            ...$values,
            'pid' => $pageId,
            'parent_page' => $pageId,
            'auto_generated' => 1,
            'inherit_to_subpages' => 1,
            'crdate' => time(),
        ]);

        return (int)$connection->lastInsertId(self::FRAGMENT_TABLE);
    }

    /**
     * @param list<int>|null $accessiblePageIds
     */
    private function getQueryBuilderForDemand(PagePromptFragmentDemand $demand, ?array $accessiblePageIds): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::FRAGMENT_TABLE);
        $qb->from(self::FRAGMENT_TABLE)
            ->innerJoin(
                self::FRAGMENT_TABLE,
                self::PAGES_TABLE,
                self::PAGES_TABLE,
                $qb->expr()->eq(self::PAGES_TABLE . '.uid', $qb->quoteIdentifier(self::FRAGMENT_TABLE . '.parent_page')),
            );

        if ($demand->hasScope() && $demand->getScope() !== PromptFragmentScope::All) {
            $qb->andWhere($qb->expr()->or(
                $qb->expr()->inSet(self::FRAGMENT_TABLE . '.scope', $qb->quote(PromptFragmentScope::All->value)),
                $qb->expr()->inSet(self::FRAGMENT_TABLE . '.scope', $qb->quote($demand->getScope()->value)),
            ));
        }

        if ($demand->hasSearch()) {
            $escaped = '%' . $qb->escapeLikeWildcards($demand->getSearch()) . '%';
            $qb->andWhere($qb->expr()->or(
                $qb->expr()->like(self::FRAGMENT_TABLE . '.prompt', $qb->createNamedParameter($escaped)),
                $qb->expr()->like(self::FRAGMENT_TABLE . '.examples', $qb->createNamedParameter($escaped)),
                $qb->expr()->like(self::FRAGMENT_TABLE . '.title', $qb->createNamedParameter($escaped)),
            ));
        }

        if ($accessiblePageIds !== null) {
            $qb->andWhere($qb->expr()->in(
                'pages.uid',
                $qb->createNamedParameter($accessiblePageIds, Connection::PARAM_INT_ARRAY),
            ));
        }

        return $qb;
    }
}
