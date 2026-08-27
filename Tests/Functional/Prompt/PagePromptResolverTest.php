<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Prompt;

use B13\Aim\Prompt\PagePromptResolver;
use B13\Aim\Prompt\PromptFragmentScope;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies additive-per-fragment inheritance across a small page tree.
 * tx_aim_prompt_fragment is a reusable library entry with no page relation
 * of its own; tx_aim_page_prompt_fragment is the per-page assignment that
 * carries `parent_page` and `inherit_to_subpages`:
 *
 *   1 (siteroot)
 *     assignment -> fragment A: scope=all, inherit_to_subpages=1, "Formal, third-person tone."
 *     assignment -> fragment B: scope=imageGeneration, inherit_to_subpages=0, "Add a watermark."
 *   +-- 2 (no own assignments)
 *       +-- 3 (own assignment -> fragment C: scope=text, inherit_to_subpages=1, "Use second person.")
 *   +-- 5 (tx_aim_disable_inherited_fragments=1, no own assignments)
 *       +-- 6 (no own assignments; page 5's disable flag must not propagate here)
 *   +-- 7 (Page TSconfig: aim.promptFragments.extra.prompt/.scope=text)
 *   +-- 8 (is_siteroot=1, a site nested under page 1's tree)
 *       +-- 9 (child of the nested site; must not see fragment A from page 1)
 *   4 (unrelated siteroot, no assignments anywhere)
 *   10 (siteroot; assignment -> fragment with a workspace-modified counterpart, see the two
 *       workspace-specific tests)
 *   11 (siteroot; assignment -> fragment D: scope=all, "Be concise." + examples "Q: ... A: ...")
 *   12 (siteroot; assignment -> fragment E: scope=text,vision, inherit_to_subpages=1, "Multi-scope DB instruction.")
 *       +-- 14 (no own assignments; inherits fragment E from page 12)
 *   13 (siteroot; Page TSconfig: aim.promptFragments.extra.prompt/.scope=text,vision)
 *   15, 16 (two unrelated siteroots, both assigned to the SAME library
 *       fragment R, proving reuse across pages, the whole point of the
 *       library/assignment split)
 */
final class PagePromptResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Siteroot', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Child']);
        $pages->insert('pages', ['uid' => 3, 'pid' => 2, 'title' => 'Grandchild']);
        $pages->insert('pages', ['uid' => 4, 'pid' => 0, 'title' => 'Unrelated siteroot', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 5, 'pid' => 1, 'title' => 'Disables inherited fragments', 'tx_aim_disable_inherited_fragments' => 1]);
        $pages->insert('pages', ['uid' => 6, 'pid' => 5, 'title' => 'Child of the disabling page']);
        $pages->insert('pages', ['uid' => 7, 'pid' => 1, 'title' => 'Page TSconfig fragment', 'TSconfig' => "aim.promptFragments.extra.prompt = TSconfig-sourced instruction.\naim.promptFragments.extra.scope = text"]);
        $pages->insert('pages', ['uid' => 8, 'pid' => 1, 'title' => 'Nested site root', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 9, 'pid' => 8, 'title' => 'Child of the nested site']);
        $pages->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Workspace test siteroot', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 11, 'pid' => 0, 'title' => 'Examples test siteroot', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 12, 'pid' => 0, 'title' => 'Multi-scope DB fragment siteroot', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 13, 'pid' => 0, 'title' => 'Multi-scope TSconfig fragment siteroot', 'is_siteroot' => 1, 'TSconfig' => "aim.promptFragments.extra.prompt = TSconfig-sourced multi-scope instruction.\naim.promptFragments.extra.scope = text,vision"]);
        $pages->insert('pages', ['uid' => 14, 'pid' => 12, 'title' => 'Child inheriting the multi-scope DB fragment']);
        $pages->insert('pages', ['uid' => 15, 'pid' => 0, 'title' => 'Reuse test siteroot A', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 16, 'pid' => 0, 'title' => 'Reuse test siteroot B', 'is_siteroot' => 1]);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');

        $fragmentAUid = $this->insertFragment($fragments, 'Fragment A', 'Formal, third-person tone.', '', PromptFragmentScope::All->value);
        $this->insertAssignment($assignments, 1, $fragmentAUid, 1);

        $fragmentBUid = $this->insertFragment($fragments, 'Fragment B', 'Add a small diagonal watermark.', '', PromptFragmentScope::ImageGeneration->value);
        $this->insertAssignment($assignments, 1, $fragmentBUid, 0);

        $fragmentCUid = $this->insertFragment($fragments, 'Fragment C', 'Use second person.', '', PromptFragmentScope::Text->value);
        $this->insertAssignment($assignments, 3, $fragmentCUid, 1);

        $fragmentDUid = $this->insertFragment(
            $fragments,
            'Fragment D',
            'Be concise.',
            "Q: How long should a product summary be?\nA: Two sentences, no more.",
            PromptFragmentScope::All->value,
        );
        $this->insertAssignment($assignments, 11, $fragmentDUid, 1);

        $fragmentEUid = $this->insertFragment(
            $fragments,
            'Fragment E',
            'Multi-scope DB instruction.',
            '',
            PromptFragmentScope::Text->value . ',' . PromptFragmentScope::Vision->value,
        );
        $this->insertAssignment($assignments, 12, $fragmentEUid, 1);

        $reusedFragmentUid = $this->insertFragment($fragments, 'Shared fragment', 'Shared brand voice.', '', PromptFragmentScope::All->value);
        $this->insertAssignment($assignments, 15, $reusedFragmentUid, 1);
        $this->insertAssignment($assignments, 16, $reusedFragmentUid, 1);

        $liveFragmentUid = $this->insertFragment($fragments, 'Workspace-editable fragment', 'Live tone text.', '', PromptFragmentScope::All->value, ['t3ver_wsid' => 0, 't3ver_oid' => 0]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 0,
            'title' => 'Workspace-editable fragment',
            'prompt' => 'Workspace-edited tone text.',
            'scope' => PromptFragmentScope::All->value,
            't3ver_wsid' => 1,
            't3ver_oid' => $liveFragmentUid,
            't3ver_state' => 0,
        ]);
        $this->insertAssignment($assignments, 10, $liveFragmentUid, 1);
    }

    private function insertFragment(
        Connection $connection,
        string $title,
        string $prompt,
        string $examples,
        string $scope,
        array $extra = [],
    ): int {
        $connection->insert('tx_aim_prompt_fragment', [
            'pid' => 0,
            'title' => $title,
            'prompt' => $prompt,
            'examples' => $examples,
            'scope' => $scope,
            ...$extra,
        ]);
        return (int)$connection->lastInsertId();
    }

    private function insertAssignment(Connection $connection, int $pageId, int $fragmentUid, int $inheritToSubpages): void
    {
        $connection->insert('tx_aim_page_prompt_fragment', [
            'pid' => $pageId,
            'parent_page' => $pageId,
            'fragment' => $fragmentUid,
            'inherit_to_subpages' => $inheritToSubpages,
        ]);
    }

    #[Test]
    public function resolvesOwnAllScopeFragmentOnTheSiterootForAnyRequestedScope(): void
    {
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame('Formal, third-person tone.', $resolver->resolve(1, PromptFragmentScope::Text));
        self::assertSame('Formal, third-person tone.', $resolver->resolve(1, PromptFragmentScope::Vision));
    }

    #[Test]
    public function inheritsAllScopeFragmentButNotNonInheritingImageFragment(): void
    {
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame(
            'Formal, third-person tone.',
            $resolver->resolve(2, PromptFragmentScope::ImageGeneration),
        );
    }

    #[Test]
    public function ownFragmentSupplementsInsteadOfReplacingInheritedAncestorFragment(): void
    {
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame(
            "Formal, third-person tone.\n\nUse second person.",
            $resolver->resolve(3, PromptFragmentScope::Text),
        );
    }

    #[Test]
    public function ownNonInheritingFragmentStillAppliesToItsOwnPage(): void
    {
        // Fragment B's assignment has inherit_to_subpages=0, so it must not
        // cascade to page 2 (see
        // inheritsAllScopeFragmentButNotNonInheritingImageFragment above),
        // but it still applies when resolving for page 1 itself.
        self::assertSame(
            "Formal, third-person tone.\n\nAdd a small diagonal watermark.",
            $this->get(PagePromptResolver::class)->resolve(1, PromptFragmentScope::ImageGeneration),
        );
    }

    #[Test]
    public function returnsNullWhenNoFragmentInTheRootlineMatches(): void
    {
        self::assertNull($this->get(PagePromptResolver::class)->resolve(4, PromptFragmentScope::All));
    }

    #[Test]
    public function disableInheritedFragmentsSkipsAncestorFragmentsForThatPageOnly(): void
    {
        self::assertNull($this->get(PagePromptResolver::class)->resolve(5, PromptFragmentScope::All));
    }

    #[Test]
    public function disableInheritedFragmentsIsPageLocalNotASubtreeBoundary(): void
    {
        // Page 5 disables inherited fragments for itself, but page 6 (its
        // child) must still inherit fragment A from page 1 normally.
        self::assertSame(
            'Formal, third-person tone.',
            $this->get(PagePromptResolver::class)->resolve(6, PromptFragmentScope::All),
        );
    }

    #[Test]
    public function pageTsConfigFragmentsMergeAfterDbFragmentsForMatchingScope(): void
    {
        self::assertSame(
            "Formal, third-person tone.\n\nTSconfig-sourced instruction.",
            $this->get(PagePromptResolver::class)->resolve(7, PromptFragmentScope::Text),
        );
    }

    #[Test]
    public function pageTsConfigFragmentsAreExcludedForNonMatchingScope(): void
    {
        self::assertSame(
            'Formal, third-person tone.',
            $this->get(PagePromptResolver::class)->resolve(7, PromptFragmentScope::Vision),
        );
    }

    #[Test]
    public function doesNotInheritFragmentsAcrossANestedSiteBoundary(): void
    {
        // Page 8 is itself a site root nested under page 1's tree. Page 9
        // (its child) must not see page 1's fragment A: resolution stops at
        // the nearest is_siteroot ancestor (page 8), which has none.
        self::assertNull($this->get(PagePromptResolver::class)->resolve(9, PromptFragmentScope::All));
    }

    #[Test]
    public function theNestedSiterootItselfDoesNotInheritFromAboveEither(): void
    {
        self::assertNull($this->get(PagePromptResolver::class)->resolve(8, PromptFragmentScope::All));
    }

    #[Test]
    public function liveContextResolvesTheLiveFragmentText(): void
    {
        self::assertSame(
            'Live tone text.',
            $this->get(PagePromptResolver::class)->resolve(10, PromptFragmentScope::All),
        );
    }

    #[Test]
    public function workspaceModifiedFragmentIsVisibleWhenResolvingWithinThatWorkspace(): void
    {
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));

        self::assertSame(
            'Workspace-edited tone text.',
            $this->get(PagePromptResolver::class)->resolve(10, PromptFragmentScope::All),
        );
    }

    #[Test]
    public function examplesAreAppendedAfterThatSameFragmentsPromptUnderAnExamplesHeading(): void
    {
        self::assertSame(
            "Be concise.\n\nExamples:\nQ: How long should a product summary be?\nA: Two sentences, no more.",
            $this->get(PagePromptResolver::class)->resolve(11, PromptFragmentScope::All),
        );
    }

    #[Test]
    public function fragmentsWithoutExamplesComposeExactlyAsBeforeWithNoStrayExamplesHeading(): void
    {
        // Fragment A never sets `examples`, so resolution must be
        // byte-identical to pre-examples-field behavior, with no "Examples:"
        // text appearing.
        self::assertSame('Formal, third-person tone.', $this->get(PagePromptResolver::class)->resolve(1, PromptFragmentScope::Text));
    }

    #[Test]
    public function dbFragmentWithMultipleScopesMatchesEachOfThemButNotOthers(): void
    {
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame('Multi-scope DB instruction.', $resolver->resolve(12, PromptFragmentScope::Text));
        self::assertSame('Multi-scope DB instruction.', $resolver->resolve(12, PromptFragmentScope::Vision));
        self::assertNull($resolver->resolve(12, PromptFragmentScope::Translation));
    }

    #[Test]
    public function pageTsConfigFragmentWithMultipleScopesMatchesEachOfThemButNotOthers(): void
    {
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame('TSconfig-sourced multi-scope instruction.', $resolver->resolve(13, PromptFragmentScope::Text));
        self::assertSame('TSconfig-sourced multi-scope instruction.', $resolver->resolve(13, PromptFragmentScope::Vision));
        self::assertNull($resolver->resolve(13, PromptFragmentScope::Translation));
    }

    #[Test]
    public function previewingAsAllCapabilitiesMatchesAFragmentNotTaggedWithTheLiteralAllSentinel(): void
    {
        // Regression: fragment E on page 12 is tagged "text,vision", not
        // the literal "all" sentinel (new fragments default to all 6
        // concrete capabilities individually checked, not to that
        // sentinel). Requesting PromptFragmentScope::All (only ever done by
        // the "preview as capability" picker, never by a real AI request)
        // must still match it, both on the page that owns the assignment
        // directly and on a subpage that only inherits it.
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame('Multi-scope DB instruction.', $resolver->resolve(12, PromptFragmentScope::All));
        self::assertSame('Multi-scope DB instruction.', $resolver->resolve(14, PromptFragmentScope::All));
    }

    #[Test]
    public function theSameLibraryFragmentCanBeAssignedToMultipleUnrelatedPages(): void
    {
        // The whole point of splitting the reusable library from the
        // per-page assignment: one fragment row, two independent
        // assignments on unrelated site roots, both resolve its content.
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame('Shared brand voice.', $resolver->resolve(15, PromptFragmentScope::All));
        self::assertSame('Shared brand voice.', $resolver->resolve(16, PromptFragmentScope::All));

        $fragmentRowCount = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')
            ->count('uid', 'tx_aim_prompt_fragment', ['title' => 'Shared fragment']);
        self::assertSame(1, $fragmentRowCount);
    }

    /**
     * Regression test: two assignments sharing the same `sorting` value
     * (e.g. both left at the default 0, as upsertAutoDetectedFragment()
     * always does since it bypasses DataHandler entirely) used to fall
     * back to undefined DB order here, with no tie-breaker at all - unlike
     * PagePromptFragmentRepository::enrichWithFragmentSummary()'s own
     * listing, which tie-broke by fragment title. The two could then
     * disagree on order for the exact same page, showing an editor one
     * order in the Preview UI while actually composing the prompt in a
     * different one. Both now tie-break by the assignment's own uid.
     *
     * Note: on this suite's SQLite backend, a bare unordered scan already
     * happens to return rows in insertion (uid) order, so this test alone
     * can't empirically fail against a reverted, tie-breaker-less version
     * of the fix (verified while writing it) - the explicit tie-break is
     * still required for correctness on backends without that incidental
     * guarantee (e.g. MySQL/Postgres query planners are free to choose a
     * different scan order). This test documents and locks in the intended
     * order regardless.
     */
    #[Test]
    public function tiedSortingValuesResolveInAssignmentUidOrder(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 17, 'pid' => 0, 'title' => 'Tied sorting siteroot', 'is_siteroot' => 1]);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');

        // Titled so that alphabetical order would give the WRONG result
        // (Zulu before Alpha) if title were still consulted anywhere -
        // only assignment insertion/uid order predicts "Zulu, then Alpha".
        $zuluUid = $this->insertFragment($fragments, 'Zulu fragment', 'Zulu tone.', '', PromptFragmentScope::All->value);
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 17, 'parent_page' => 17, 'fragment' => $zuluUid, 'inherit_to_subpages' => 1, 'sorting' => 0]);

        $alphaUid = $this->insertFragment($fragments, 'Alpha fragment', 'Alpha tone.', '', PromptFragmentScope::All->value);
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 17, 'parent_page' => 17, 'fragment' => $alphaUid, 'inherit_to_subpages' => 1, 'sorting' => 0]);

        self::assertSame(
            "Zulu tone.\n\nAlpha tone.",
            $this->get(PagePromptResolver::class)->resolve(17, PromptFragmentScope::All),
        );
    }

    #[Test]
    public function cachesResolutionPerPageAndScopeWithinOneRequest(): void
    {
        $resolver = $this->get(PagePromptResolver::class);
        $first = $resolver->resolve(3, PromptFragmentScope::Text);

        $connectionPool = $this->getConnectionPool();
        $fragmentUid = (int)$connectionPool->getConnectionForTable('tx_aim_page_prompt_fragment')
            ->select(['fragment'], 'tx_aim_page_prompt_fragment', ['parent_page' => 3])
            ->fetchOne();
        $connectionPool->getConnectionForTable('tx_aim_prompt_fragment')->update(
            'tx_aim_prompt_fragment',
            ['prompt' => 'Changed after first resolution.'],
            ['uid' => $fragmentUid],
        );

        self::assertSame($first, $resolver->resolve(3, PromptFragmentScope::Text));
    }

    /**
     * Regression test: resolve()'s own catch(\Throwable) around
     * RootlineUtility - the only failure path exercised by any test in this
     * class until now - must degrade to "no tone-of-voice for this page"
     * rather than letting the exception propagate and break whatever
     * triggered the resolution (e.g. a real, unrelated AI request). A
     * non-existent page id is the simplest way to make RootlineUtility
     * throw without mocking it directly.
     */
    #[Test]
    public function resolveReturnsNullInsteadOfThrowingWhenTheRootlineCannotBeResolved(): void
    {
        self::assertNull($this->get(PagePromptResolver::class)->resolve(999999, PromptFragmentScope::All));
    }
}
