<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Service;

use B13\Aim\Service\SiteVoiceCalibrator;
use B13\Aim\Service\VoiceCalibrationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers buildCombinedText() only: the crawl-and-accumulate half of
 * SiteVoiceCalibrator that needs just the database, not a working AI
 * provider. calibrateForRootPage() itself (which additionally calls
 * VoiceCalibrationService::calibrate(), dispatching a real AI request) is
 * left untested here, same as every other AI-dispatch call site in this
 * codebase (Ai/AiRequestBuilder have no tests either, since there's no fake
 * AiProviderInterface fixture in this test suite to dispatch against).
 *
 * Base page tree (shared by every test):
 *   1 "Root"   -- short real content
 *   2 "Child"  -- pid=1, short real content
 */
final class SiteVoiceCalibratorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root']);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Child']);

        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 1,
            'pid' => 1,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'header' => 'Welcome',
            'bodytext' => 'A short, friendly welcome message.',
        ]);
        $content->insert('tt_content', [
            'uid' => 2,
            'pid' => 2,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'header' => 'Second page',
            'bodytext' => 'A short second page, easily well within budget alongside the root.',
        ]);
    }

    #[Test]
    public function combinesAllCrawledPagesWhenWellWithinBudget(): void
    {
        $result = $this->calibrator()->buildCombinedText(1, 1, 100);

        self::assertSame([1, 2], $result['pagesUsed']);
        self::assertStringContainsString('Welcome', $result['text']);
        self::assertStringContainsString('Second page', $result['text']);
    }

    #[Test]
    public function stopsAccumulatingOnceTheNextWholePageWouldExceedTheBudget(): void
    {
        // A third child whose own content alone is bigger than the entire
        // budget: accumulation must stop before it, not truncate it in.
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'Huge child']);
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 3,
            'pid' => 3,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'header' => 'Huge page',
            'bodytext' => str_repeat('word ', 3000), // ~15000 chars, over VoiceCalibrationService::MAX_LENGTH alone
        ]);

        $result = $this->calibrator()->buildCombinedText(1, 1, 100);

        self::assertSame([1, 2], $result['pagesUsed']);
        self::assertLessThanOrEqual(VoiceCalibrationService::MAX_LENGTH, mb_strlen($result['text']));
    }

    #[Test]
    public function truncatesASingleOverBudgetPageRatherThanReturningNothing(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 50, 'pid' => 0, 'title' => 'Huge standalone page']);
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 50,
            'pid' => 50,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'header' => 'Huge page',
            'bodytext' => str_repeat('word ', 3000),
        ]);

        // Depth 0 on this page alone, nothing else to combine with, so the
        // "truncate the only page rather than return nothing" fallback is
        // what has to kick in.
        $result = $this->calibrator()->buildCombinedText(50, 0, 100);

        self::assertSame([50], $result['pagesUsed']);
        self::assertLessThanOrEqual(VoiceCalibrationService::MAX_LENGTH, mb_strlen($result['text']));
        self::assertNotSame('', $result['text']);
    }

    #[Test]
    public function returnsEmptyTextForAPageWithNoContentAndNoSite(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 99, 'pid' => 0, 'title' => 'Empty']);

        $result = $this->calibrator()->buildCombinedText(99, 2, 100);

        self::assertSame('', $result['text']);
        self::assertSame([], $result['pagesUsed']);
    }

    private function calibrator(): SiteVoiceCalibrator
    {
        return $this->get(SiteVoiceCalibrator::class);
    }
}
