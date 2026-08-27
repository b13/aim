<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Prompt;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Resolves the page-tree "tone of voice" prompt from two merged sources:
 * tx_aim_prompt_fragment library entries assigned to pages via
 * tx_aim_page_prompt_fragment records, and Page TSconfig
 * (aim.promptFragments.<key>.prompt/.scope, see TsConfigFragmentParser).
 *
 * A fragment's actual instruction text lives once in tx_aim_prompt_fragment
 * and can be assigned to any number of pages (e.g. the same tone-of-voice
 * fragment reused across a multi-site install's country subsites), while
 * "which pages use it" and "does it cascade to subpages" are per-assignment
 * facts that live on tx_aim_page_prompt_fragment instead.
 *
 * DB fragments: additive-per-fragment inheritance: RootlineUtility::get()
 * returns the target page first and the site root last; reversed to
 * root-first order for composition (general tone reads before page-specific
 * instructions). A page's own fragments always apply to itself. An
 * ancestor's fragment only cascades to descendants if its
 * "inherit_to_subpages" flag is set. A child page adding its own fragment
 * supplements, never silently replaces, what it inherited. If the target
 * page has tx_aim_disable_inherited_fragments set, every ancestor fragment
 * is skipped for that page's own resolution (its own fragments still
 * apply); this is page-local, not a subtree boundary. The page's children
 * resolve independently and keep inheriting from the original ancestors.
 *
 * The DB-fragment rootline walk stops at (and includes) the nearest
 * `is_siteroot` ancestor. In a nested-site install (one site's page tree
 * living under another site's), an editor setting a page-tone fragment has
 * no way to know or expect it to leak into an unrelated site. Page TSconfig
 * fragments deliberately don't get the same treatment: they go through
 * TYPO3 core's own getPagesTSconfig(), which has never respected site
 * boundaries; that's an established, technical-audience convention
 * (integrators can already use the `>` clear operator).
 *
 * DB fragments respect workspace overlays for edits to either the
 * assignment or the library fragment itself. Not translatable: neither
 * TCA has language/transOrig configuration, since nothing in the
 * resolution pipeline is language-aware yet.
 *
 * Deliberately not applied to sys_file_metadata / FAL (see
 * TonePromptCompositionMiddleware): files have no meaningful page-tree
 * position (pid is always 0), so this resolver is only ever consulted for
 * requests that carry a real page id.
 *
 * Fragments may carry an optional `examples` text alongside `prompt`: a
 * few concrete input/output pairs, appended after that same fragment's
 * instruction under an "Examples:" heading, so an example always travels
 * with the instruction it demonstrates rather than being pooled separately.
 * Only DB fragments get this dedicated field: Page/User TSconfig fragments
 * (TsConfigFragmentParser) and code-registered fragments
 * (PromptFragmentRegistry) are already raw strings authored by a technical
 * integrator or extension developer, who can inline an examples block into
 * their own `prompt` value directly; the dedicated field only earns its
 * keep on this DB-fragment UI, the one surface aimed at non-technical
 * editors with per-field soft-max guardrails.
 */
class PagePromptResolver
{
    private const ASSIGNMENT_TABLE = 'tx_aim_page_prompt_fragment';
    private const FRAGMENT_TABLE = 'tx_aim_prompt_fragment';

    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolve(int $pageId, PromptFragmentScope $scope): ?string
    {
        // Workspace-keyed: fetchFragmentsByPage() overlays each row with the
        // current workspace's version (versionOL()), so the same page+scope
        // can legitimately resolve differently per workspace; caching by
        // pageId+scope alone would let a live-workspace resolution leak into
        // a later draft-workspace one within the same instance's lifetime.
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id');
        $cacheKey = $pageId . ':' . $scope->value . ':' . $workspaceId;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Could not resolve rootline for page %d, skipping tone-of-voice resolution: %s',
                $pageId,
                $e->getMessage(),
            ));
            return $this->cache[$cacheKey] = null;
        }

        $siteRootline = $this->restrictToCurrentSite($rootline);

        $targetPage = null;
        foreach ($siteRootline as $page) {
            if ((int)$page['uid'] === $pageId) {
                $targetPage = $page;
                break;
            }
        }
        $disableInheritedFragments = (bool)($targetPage['tx_aim_disable_inherited_fragments'] ?? false);

        $pageIds = array_map(static fn(array $page): int => (int)$page['uid'], $siteRootline);
        $fragmentsByPage = $this->fetchFragmentsByPage($pageIds, $scope);

        $texts = [];
        foreach (array_reverse($siteRootline) as $page) {
            $isTargetPage = (int)$page['uid'] === $pageId;
            if (!$isTargetPage && $disableInheritedFragments) {
                continue;
            }
            foreach ($fragmentsByPage[(int)$page['uid']] ?? [] as $fragment) {
                if (!$isTargetPage && !$fragment['inheritToSubpages']) {
                    continue;
                }
                if ($fragment['prompt'] !== '') {
                    $block = $fragment['prompt'];
                    if ($fragment['examples'] !== '') {
                        $block .= "\n\nExamples:\n" . $fragment['examples'];
                    }
                    $texts[] = $block;
                }
            }
        }

        foreach ($this->fetchPageTsConfigFragments($pageId, $scope) as $prompt) {
            $texts[] = $prompt;
        }

        return $this->cache[$cacheKey] = ($texts === [] ? null : implode("\n\n", $texts));
    }

    /**
     * Truncates a target-first rootline to stop at (and include) the
     * nearest `is_siteroot` ancestor, so DB fragment inheritance never
     * crosses into a different site's page tree. Falls back to the full
     * rootline if no page in it is flagged as a site root (e.g. test
     * fixtures, or an install that doesn't consistently set the flag):
     * unchanged, pre-existing behavior rather than a new restriction.
     *
     * @param list<array<string, mixed>> $rootline
     * @return list<array<string, mixed>>
     */
    private function restrictToCurrentSite(array $rootline): array
    {
        // RootlineUtility's array keys don't match iteration position (its
        // internal krsort() only reverses iteration order, it doesn't
        // reindex): array_values() first so the index used for the slice
        // below actually matches positional/iteration order.
        $rootline = array_values($rootline);
        foreach ($rootline as $index => $page) {
            if ((bool)($page['is_siteroot'] ?? false)) {
                return array_slice($rootline, 0, $index + 1);
            }
        }
        return $rootline;
    }

    /**
     * @return list<string>
     */
    private function fetchPageTsConfigFragments(int $pageId, PromptFragmentScope $scope): array
    {
        try {
            $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Could not resolve Page TSconfig for page %d, skipping TSconfig-sourced fragments: %s',
                $pageId,
                $e->getMessage(),
            ));
            return [];
        }

        $fragments = TsConfigFragmentParser::parse($tsConfig['aim.']['promptFragments.'] ?? [], $this->logger);
        return $scope->filterPrompts($fragments);
    }

    /**
     * Fetches every assignment for the given pages, then the content of
     * every distinct library fragment they point to. Two queries total
     * regardless of how many pages/assignments are involved, and each
     * table is overlaid with versionOL() independently, so a workspace can
     * edit either "which fragment is assigned here" or "what does the
     * fragment itself say" without the two concerns interfering.
     *
     * Deliberately doesn't filter by scope at the SQL level: a row with a
     * corrupted/invalid scope value (only possible via direct DB manipulation
     * or a raw import bypassing the TCA dropdown) would otherwise silently
     * never match any query and vanish without a trace. Filtering in PHP
     * instead lets an invalid value be normalized to "all" with a logged
     * warning, the same treatment as the TSconfig/registry sources.
     *
     * Restricted to the live workspace at the query level (a table with no
     * such restriction would otherwise mix in every other workspace's
     * versions too, not just the current one), then overlaid per-row with
     * the current workspace's version via versionOL(), the standard core
     * idiom for "show the workspace-draft edit of this record, if any."
     *
     * @param list<int> $pageIds
     * @return array<int, list<array{prompt: string, examples: string, inheritToSubpages: bool}>> keyed by parent_page, ordered by sorting
     */
    private function fetchFragmentsByPage(array $pageIds, PromptFragmentScope $scope): array
    {
        $assignmentQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::ASSIGNMENT_TABLE);
        $assignmentQueryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));
        $assignmentRows = $assignmentQueryBuilder->select('uid', 'pid', 't3ver_oid', 't3ver_state', 'parent_page', 'fragment', 'inherit_to_subpages')
            ->from(self::ASSIGNMENT_TABLE)
            ->where(
                $assignmentQueryBuilder->expr()->in('parent_page', $assignmentQueryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('sorting')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $this->context);

        $liveAssignments = [];
        foreach ($assignmentRows as $row) {
            $pageRepository->versionOL(self::ASSIGNMENT_TABLE, $row);
            if ($row === false) {
                continue; // deleted within the current workspace
            }
            $liveAssignments[] = $row;
        }

        $fragmentUids = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['fragment'],
            $liveAssignments,
        )));
        $fragmentsByUid = $this->fetchFragmentsByUid($fragmentUids, $pageRepository);

        $byPage = [];
        foreach ($liveAssignments as $row) {
            $fragment = $fragmentsByUid[(int)$row['fragment']] ?? null;
            if ($fragment === null) {
                continue; // referenced fragment no longer exists/is deleted
            }
            if (!$scope->matches($fragment['scope'])) {
                continue;
            }
            $byPage[(int)$row['parent_page']][] = [
                'prompt' => $fragment['prompt'],
                'examples' => $fragment['examples'],
                'inheritToSubpages' => (bool)$row['inherit_to_subpages'],
            ];
        }
        return $byPage;
    }

    /**
     * @param list<int> $fragmentUids
     * @return array<int, array{prompt: string, examples: string, scope: list<string>}> keyed by fragment uid
     */
    private function fetchFragmentsByUid(array $fragmentUids, PageRepository $pageRepository): array
    {
        if ($fragmentUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FRAGMENT_TABLE);
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));
        $rows = $queryBuilder->select('uid', 'pid', 't3ver_oid', 't3ver_state', 'prompt', 'examples', 'scope')
            ->from(self::FRAGMENT_TABLE)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($fragmentUids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byUid = [];
        foreach ($rows as $row) {
            $pageRepository->versionOL(self::FRAGMENT_TABLE, $row);
            if ($row === false) {
                continue; // deleted within the current workspace
            }
            $byUid[(int)$row['uid']] = [
                'prompt' => trim((string)$row['prompt']),
                'examples' => trim((string)($row['examples'] ?? '')),
                'scope' => PromptFragmentScope::parseList((string)$row['scope'], $this->logger),
            ];
        }
        return $byUid;
    }
}
