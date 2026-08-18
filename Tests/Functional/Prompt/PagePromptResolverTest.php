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
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies additive-per-fragment inheritance for tx_aim_prompt_fragment
 * across a small page tree:
 *
 *   1 (siteroot)
 *     fragment A: scope=all, inherit_to_subpages=1, "Formal, third-person tone."
 *     fragment B: scope=imageGeneration, inherit_to_subpages=0, "Add a watermark."
 *   +-- 2 (no own fragments)
 *       +-- 3 (own fragment C: scope=text, inherit_to_subpages=1, "Use second person.")
 *   +-- 5 (tx_aim_disable_inherited_fragments=1, no own fragments)
 *       +-- 6 (no own fragments — page 5's disable flag must not propagate here)
 *   +-- 7 (Page TSconfig: aim.promptFragments.extra.prompt/.scope=text)
 *   +-- 8 (is_siteroot=1 — a site nested under page 1's tree)
 *       +-- 9 (child of the nested site; must not see fragment A from page 1)
 *   4 (unrelated siteroot, no fragments anywhere)
 *   10 (siteroot; fragment with a workspace-modified counterpart, see the two
 *       workspace-specific tests)
 *   11 (siteroot; fragment D: scope=all, "Be concise." + examples "Q: ... A: ...")
 *   12 (siteroot; fragment E: scope=text,vision, inherit_to_subpages=1, "Multi-scope DB instruction.")
 *       +-- 14 (no own fragments; inherits fragment E from page 12)
 *   13 (siteroot; Page TSconfig: aim.promptFragments.extra.prompt/.scope=text,vision)
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

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 1,
            'parent_page' => 1,
            'sorting' => 1,
            'title' => 'Fragment A',
            'prompt' => 'Formal, third-person tone.',
            'scope' => PromptFragmentScope::All->value,
            'inherit_to_subpages' => 1,
        ]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 1,
            'parent_page' => 1,
            'sorting' => 2,
            'title' => 'Fragment B',
            'prompt' => 'Add a small diagonal watermark.',
            'scope' => PromptFragmentScope::ImageGeneration->value,
            'inherit_to_subpages' => 0,
        ]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 3,
            'parent_page' => 3,
            'sorting' => 1,
            'title' => 'Fragment C',
            'prompt' => 'Use second person.',
            'scope' => PromptFragmentScope::Text->value,
            'inherit_to_subpages' => 1,
        ]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 11,
            'parent_page' => 11,
            'sorting' => 1,
            'title' => 'Fragment D',
            'prompt' => 'Be concise.',
            'examples' => "Q: How long should a product summary be?\nA: Two sentences, no more.",
            'scope' => PromptFragmentScope::All->value,
            'inherit_to_subpages' => 1,
        ]);

        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 12,
            'parent_page' => 12,
            'sorting' => 1,
            'title' => 'Fragment E',
            'prompt' => 'Multi-scope DB instruction.',
            'scope' => PromptFragmentScope::Text->value . ',' . PromptFragmentScope::Vision->value,
            'inherit_to_subpages' => 1,
        ]);

        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 10,
            'parent_page' => 10,
            'sorting' => 1,
            'title' => 'Workspace-editable fragment',
            'prompt' => 'Live tone text.',
            'scope' => PromptFragmentScope::All->value,
            'inherit_to_subpages' => 1,
            't3ver_wsid' => 0,
            't3ver_oid' => 0,
        ]);
        $liveFragmentUid = (int)$fragments->lastInsertId();
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 10,
            'parent_page' => 10,
            'sorting' => 1,
            'title' => 'Workspace-editable fragment',
            'prompt' => 'Workspace-edited tone text.',
            'scope' => PromptFragmentScope::All->value,
            'inherit_to_subpages' => 1,
            't3ver_wsid' => 1,
            't3ver_oid' => $liveFragmentUid,
            't3ver_state' => 0,
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
        // Fragment B has inherit_to_subpages=0 — it must not cascade to page 2
        // (see inheritsAllScopeFragmentButNotNonInheritingImageFragment above),
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
        // (its child) must not see page 1's fragment A — resolution stops
        // at the nearest is_siteroot ancestor (page 8), which has none.
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
        // Fragment A never sets `examples` — resolution must be byte-identical
        // to pre-examples-field behavior, with no "Examples:" text appearing.
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
        // sentinel). Requesting PromptFragmentScope::All — only ever done
        // by the "preview as capability" picker, never by a real AI
        // request — must still match it, both on the page that owns the
        // fragment directly and on a subpage that only inherits it.
        $resolver = $this->get(PagePromptResolver::class);

        self::assertSame('Multi-scope DB instruction.', $resolver->resolve(12, PromptFragmentScope::All));
        self::assertSame('Multi-scope DB instruction.', $resolver->resolve(14, PromptFragmentScope::All));
    }

    #[Test]
    public function cachesResolutionPerPageAndScopeWithinOneRequest(): void
    {
        $resolver = $this->get(PagePromptResolver::class);
        $first = $resolver->resolve(3, PromptFragmentScope::Text);

        $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')->update(
            'tx_aim_prompt_fragment',
            ['prompt' => 'Changed after first resolution.'],
            ['parent_page' => 3],
        );

        self::assertSame($first, $resolver->resolve(3, PromptFragmentScope::Text));
    }
}
