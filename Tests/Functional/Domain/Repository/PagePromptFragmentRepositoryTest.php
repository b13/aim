<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Domain\Repository;

use B13\Aim\Domain\Repository\PagePromptFragmentDemand;
use B13\Aim\Domain\Repository\PagePromptFragmentRepository;
use B13\Aim\Prompt\PromptFragmentScope;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Page tree used across these tests:
 *
 *   1 "Has a text fragment"       -- fragment: scope=text, prompt contains "banana"
 *   2 "Has an image fragment"     -- fragment: scope=imageGeneration
 *   3 "No fragments"              -- no tx_aim_prompt_fragment rows at all
 *   4 "Two fragments"             -- two fragments (text + all) -- must appear exactly once
 *   5 "Multi-scope fragment"      -- one fragment: scope=text,vision
 */
final class PagePromptFragmentRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Has a text fragment']);
        $pages->insert('pages', ['uid' => 2, 'pid' => 0, 'title' => 'Has an image fragment']);
        $pages->insert('pages', ['uid' => 3, 'pid' => 0, 'title' => 'No fragments']);
        $pages->insert('pages', ['uid' => 4, 'pid' => 0, 'title' => 'Two fragments']);
        $pages->insert('pages', ['uid' => 5, 'pid' => 0, 'title' => 'Multi-scope fragment']);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 1,
            'parent_page' => 1,
            'title' => 'Fragment A',
            'prompt' => 'Always mention the banana promotion.',
            'scope' => PromptFragmentScope::Text->value,
        ]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 2,
            'parent_page' => 2,
            'title' => 'Fragment B',
            'prompt' => 'Add a watermark.',
            'scope' => PromptFragmentScope::ImageGeneration->value,
        ]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 4,
            'parent_page' => 4,
            'title' => 'Fragment C1',
            'prompt' => 'Formal tone.',
            'scope' => PromptFragmentScope::Text->value,
        ]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 4,
            'parent_page' => 4,
            'title' => 'Fragment C2',
            'prompt' => 'Brand voice.',
            'scope' => PromptFragmentScope::All->value,
        ]);
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 5,
            'parent_page' => 5,
            'title' => 'Fragment D',
            'prompt' => 'Ask for both text and vision styling.',
            'scope' => PromptFragmentScope::Text->value . ',' . PromptFragmentScope::Vision->value,
        ]);
    }

    private function repository(): PagePromptFragmentRepository
    {
        return $this->get(PagePromptFragmentRepository::class);
    }

    #[Test]
    public function onlyListsPagesThatHaveAtLeastOneFragment(): void
    {
        $rows = $this->repository()->findByDemand(new PagePromptFragmentDemand(), null);

        // Default sort is by title, ascending: "Multi-scope fragment" (5)
        // sorts before "Two fragments" (4).
        self::assertSame([1, 2, 5, 4], array_map(static fn(array $row): int => (int)$row['uid'], $rows));
    }

    #[Test]
    public function aPageWithMultipleFragmentsAppearsExactlyOnce(): void
    {
        $rows = $this->repository()->findByDemand(new PagePromptFragmentDemand(), null);

        $matchingPage4 = array_filter($rows, static fn(array $row): bool => (int)$row['uid'] === 4);
        self::assertCount(1, $matchingPage4);
    }

    #[Test]
    public function scopeFilterOnlyMatchesThatScopeOrAllScopeFragments(): void
    {
        $rows = $this->repository()->findByDemand(new PagePromptFragmentDemand(scope: PromptFragmentScope::ImageGeneration), null);

        // Page 2's fragment is imageGeneration-scoped; page 4's "Fragment C2"
        // is all-scoped, which must match every specific-scope filter too.
        self::assertSame([2, 4], array_map(static fn(array $row): int => (int)$row['uid'], $rows));
    }

    #[Test]
    public function searchMatchesPromptContent(): void
    {
        $rows = $this->repository()->findByDemand(new PagePromptFragmentDemand(search: 'banana'), null);

        self::assertSame([1], array_map(static fn(array $row): int => (int)$row['uid'], $rows));
    }

    #[Test]
    public function searchIsCaseAndWildcardSafe(): void
    {
        self::assertSame([], array_map(
            static fn(array $row): int => (int)$row['uid'],
            $this->repository()->findByDemand(new PagePromptFragmentDemand(search: 'nonexistent term'), null),
        ));
    }

    #[Test]
    public function anEmptyAccessiblePageIdListShortCircuitsToNoResultsWithoutQuerying(): void
    {
        self::assertSame([], $this->repository()->findByDemand(new PagePromptFragmentDemand(), []));
        self::assertSame(0, $this->repository()->countByDemand(new PagePromptFragmentDemand(), []));
    }

    #[Test]
    public function restrictsToTheGivenAccessiblePageIdsWhenNotNull(): void
    {
        $rows = $this->repository()->findByDemand(new PagePromptFragmentDemand(), [1, 3]);

        // Page 3 has no fragments so it's excluded regardless; page 1 is in
        // the accessible set and has a fragment, so it's included. Pages 2
        // and 4 are excluded purely by the accessible-id restriction.
        self::assertSame([1], array_map(static fn(array $row): int => (int)$row['uid'], $rows));
    }

    #[Test]
    public function nullAccessiblePageIdsMeansUnrestricted(): void
    {
        self::assertCount(4, $this->repository()->findByDemand(new PagePromptFragmentDemand(), null));
    }

    #[Test]
    public function countByDemandCountsDistinctPagesNotFragmentRows(): void
    {
        // Page 4 has two matching fragments, which must count as one page.
        self::assertSame(4, $this->repository()->countByDemand(new PagePromptFragmentDemand(), null));
    }

    #[Test]
    public function scopeFilterMatchesAFragmentThatListsTheRequestedCapabilityAmongSeveral(): void
    {
        $textRows = $this->repository()->findByDemand(new PagePromptFragmentDemand(scope: PromptFragmentScope::Text), null);
        // Page 5's fragment lists "text" among several capabilities. Default
        // sort is by title, ascending: "Multi-scope fragment" (5) sorts
        // before "Two fragments" (4).
        self::assertSame([1, 5, 4], array_map(static fn(array $row): int => (int)$row['uid'], $textRows));

        $visionRows = $this->repository()->findByDemand(new PagePromptFragmentDemand(scope: PromptFragmentScope::Vision), null);
        // Page 4's "Fragment C2" is all-scoped (matches every specific-scope
        // filter too); page 5's fragment lists "vision" among several.
        self::assertSame([5, 4], array_map(static fn(array $row): int => (int)$row['uid'], $visionRows));

        $translationRows = $this->repository()->findByDemand(new PagePromptFragmentDemand(scope: PromptFragmentScope::Translation), null);
        self::assertSame([4], array_map(static fn(array $row): int => (int)$row['uid'], $translationRows));
    }

    #[Test]
    public function enrichWithFragmentSummaryAddsCountAndDistinctScopesPerPage(): void
    {
        $rows = $this->repository()->findByDemand(new PagePromptFragmentDemand(), null);
        $enriched = $this->repository()->enrichWithFragmentSummary($rows);

        $page4 = current(array_filter($enriched, static fn(array $row): bool => (int)$row['uid'] === 4));
        self::assertSame(2, $page4['fragmentCount']);
        self::assertSame([PromptFragmentScope::Text->value, PromptFragmentScope::All->value], $page4['scopes']);

        $page1 = current(array_filter($enriched, static fn(array $row): bool => (int)$row['uid'] === 1));
        self::assertSame(1, $page1['fragmentCount']);
        self::assertSame([PromptFragmentScope::Text->value], $page1['scopes']);
    }

    #[Test]
    public function enrichWithFragmentSummaryReportsEveryCapabilityOfAMultiScopeFragmentSeparately(): void
    {
        $rows = $this->repository()->findByDemand(new PagePromptFragmentDemand(), null);
        $enriched = $this->repository()->enrichWithFragmentSummary($rows);

        $page5 = current(array_filter($enriched, static fn(array $row): bool => (int)$row['uid'] === 5));
        self::assertSame(1, $page5['fragmentCount']);
        self::assertSame([PromptFragmentScope::Text->value, PromptFragmentScope::Vision->value], $page5['scopes']);
    }

    #[Test]
    public function pagesWithoutFragmentsCanExistAlongsideEnrichedResultsWithoutErrors(): void
    {
        self::assertSame([], $this->repository()->enrichWithFragmentSummary([]));
    }

    #[Test]
    public function upsertAutoDetectedFragmentCreatesANewFragmentWhenNoneExistsYet(): void
    {
        // Page 3 ("No fragments") has none seeded in setUp().
        $uid = $this->repository()->upsertAutoDetectedFragment(3, 'Tone of voice (auto-detected)', 'Be warm.', 'Q: Hi\nA: Hello!', ['text', 'vision']);

        $row = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')
            ->select(['*'], 'tx_aim_prompt_fragment', ['uid' => $uid])
            ->fetchAssociative();

        self::assertSame(3, (int)$row['pid']);
        self::assertSame(3, (int)$row['parent_page']);
        self::assertSame(1, (int)$row['auto_generated']);
        self::assertSame(1, (int)$row['inherit_to_subpages']);
        self::assertSame('Be warm.', $row['prompt']);
        self::assertSame('text,vision', $row['scope']);
    }

    #[Test]
    public function upsertAutoDetectedFragmentUpdatesTheSameRecordOnASecondRun(): void
    {
        $repository = $this->repository();
        $firstUid = $repository->upsertAutoDetectedFragment(3, 'Tone of voice (auto-detected)', 'First tone.', '', ['text']);
        $secondUid = $repository->upsertAutoDetectedFragment(3, 'Tone of voice (auto-detected)', 'Refreshed tone.', '', ['text']);

        self::assertSame($firstUid, $secondUid);

        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $count = $connection->count('uid', 'tx_aim_prompt_fragment', ['parent_page' => 3, 'auto_generated' => 1]);
        self::assertSame(1, $count);

        $row = $connection->select(['prompt'], 'tx_aim_prompt_fragment', ['uid' => $firstUid])->fetchAssociative();
        self::assertSame('Refreshed tone.', $row['prompt']);
    }

    #[Test]
    public function upsertAutoDetectedFragmentNeverTouchesAnEditorAuthoredFragment(): void
    {
        // Page 1 already has a hand-authored fragment from setUp() (no
        // auto_generated flag), so upserting must ADD a second, separate
        // auto-detected fragment rather than overwrite the existing one.
        $uid = $this->repository()->upsertAutoDetectedFragment(1, 'Tone of voice (auto-detected)', 'Auto tone.', '', ['text']);

        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $total = $connection->count('uid', 'tx_aim_prompt_fragment', ['parent_page' => 1]);
        self::assertSame(2, $total);

        $handAuthored = $connection->count('uid', 'tx_aim_prompt_fragment', ['parent_page' => 1, 'auto_generated' => 0]);
        self::assertSame(1, $handAuthored);
        self::assertNotSame(0, $uid);
    }
}
