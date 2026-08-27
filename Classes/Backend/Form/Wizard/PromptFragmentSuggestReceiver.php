<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend\Form\Wizard;

use TYPO3\CMS\Backend\Form\Wizard\SuggestWizardDefaultReceiver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Filters tx_aim_page_prompt_fragment's `fragment` suggest/type-ahead
 * results (config.type=group, allowed=tx_aim_prompt_fragment) down to
 * what's actually usable from the page being edited: a fragment stored at
 * pid=0 is a global library entry and always selectable; a fragment stored
 * in a sysfolder is only selectable for pages within that SAME site tree,
 * so an editor without access to pid=0 can author "their" fragments in a
 * sysfolder without leaking them into an unrelated site.
 */
final class PromptFragmentSuggestReceiver extends SuggestWizardDefaultReceiver
{
    public function __construct(string $table, array $config)
    {
        $currentPageId = (int)trim((string)($config['addWhere'] ?? ''));
        unset($config['addWhere']);

        parent::__construct($table, $config);

        if (!$this->hasFragmentReadAccess()) {
            // No tables_select on tx_aim_prompt_fragment: none of its
            // content (not even pid=0/global titles) may be browsed through
            // this field, site-scoping below is irrelevant once this
            // applies. Forcing an always-false condition here - rather than
            // e.g. returning early - keeps this a single, always-applied
            // WHERE clause instead of two divergent code paths to keep in
            // sync, and closes the suggest/type-ahead route regardless of
            // which page/form embeds this field (aim's own "Create Prompt"
            // button is only one of several ways to reach it).
            $this->queryBuilder->andWhere('1 = 0');
            return;
        }

        $allowedPageIds = $this->resolveAllowedFragmentPageIds($currentPageId);
        $orConditions = [$this->queryBuilder->expr()->eq('pid', $this->queryBuilder->createPositionalParameter(0, Connection::PARAM_INT))];
        if ($allowedPageIds !== []) {
            $orConditions[] = $this->queryBuilder->expr()->in(
                'pid',
                $this->queryBuilder->createPositionalParameter($allowedPageIds, Connection::PARAM_INT_ARRAY),
            );
        }
        $this->queryBuilder->andWhere($this->queryBuilder->expr()->or(...$orConditions));
    }

    private function hasFragmentReadAccess(): bool
    {
        return $this->getBackendUser()->check('tables_select', 'tx_aim_prompt_fragment');
    }

    /**
     * The query above already decides a pid=0 fragment is always fair game
     * ("global", the same rule the Library module's own listing uses),
     * but the parent's own per-row access check calls
     * BackendUtility::readPageAccess($row['pid'], ...), which returns false
     * for pid=0 unless the current user is an admin. Left unoverridden, every
     * global fragment silently disappeared from a non-admin's search
     * results despite the query saying otherwise. The one place in this
     * extension a non-admin editor could actually reuse the shared library
     * the whole split exists for. Sysfolder-stored fragments (pid > 0) keep
     * the real, unchanged page-access check.
     *
     * @param array<string, mixed> $row
     */
    protected function checkRecordAccess($row, $uid): bool
    {
        if (!$this->hasFragmentReadAccess()) {
            return false;
        }
        if ((int)($row['pid'] ?? -1) === 0) {
            return true;
        }
        return parent::checkRecordAccess((array)$row, (int)$uid);
    }

    /**
     * @return list<int> every distinct fragment-storage pid that belongs
     *         to the current page's own site; [] when the page/site can't
     *         be resolved, which still correctly degrades to "pid=0 only"
     *         via the OR condition above
     */
    private function resolveAllowedFragmentPageIds(int $currentPageId): array
    {
        if ($currentPageId <= 0) {
            return [];
        }
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        try {
            $siteRootPageId = $siteFinder->getSiteByPageId($currentPageId)->getRootPageId();
        } catch (\Throwable) {
            return [];
        }

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_aim_prompt_fragment');
        $distinctPids = $qb->selectLiteral('DISTINCT ' . $qb->quoteIdentifier('pid'))
            ->from('tx_aim_prompt_fragment')
            ->where($qb->expr()->gt('pid', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchFirstColumn();

        $allowedPageIds = [$siteRootPageId];
        foreach ($distinctPids as $pid) {
            $pid = (int)$pid;
            try {
                if ($siteFinder->getSiteByPageId($pid)->getRootPageId() === $siteRootPageId) {
                    $allowedPageIds[] = $pid;
                }
            } catch (\Throwable) {
                // A fragment sitting at a since-deleted/orphaned pid: never
                // matches any site, simply excluded rather than erroring.
                continue;
            }
        }
        return $allowedPageIds;
    }
}
