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

use B13\Aim\Service\PageContentExtractor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageContentExtractorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'About Us']);
        $pages->insert('pages', ['uid' => 2, 'pid' => 0, 'title' => 'Contact']);

        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 1,
            'pid' => 1,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'header' => 'Welcome',
            'subheader' => '',
            'bodytext' => 'We build things people love.',
        ]);
        $content->insert('tt_content', [
            'uid' => 2,
            'pid' => 1,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 2,
            'header' => '',
            'subheader' => '',
            'bodytext' => '<p>Line one.<br>Line two.</p>',
        ]);
        $content->insert('tt_content', [
            'uid' => 3,
            'pid' => 1,
            'sys_language_uid' => 1,
            'colPos' => 0,
            'sorting' => 3,
            'header' => 'Willkommen',
            'subheader' => '',
            'bodytext' => 'Nur auf Deutsch.',
        ]);
        $content->insert('tt_content', [
            'uid' => 4,
            'pid' => 2,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'header' => 'Get in touch',
            'subheader' => '',
            'bodytext' => 'Drop us a line any time.',
        ]);
    }

    #[Test]
    public function extractsAndCleansTextForASinglePage(): void
    {
        $text = $this->get(PageContentExtractor::class)->extractText([1]);

        self::assertStringContainsString('Welcome', $text);
        self::assertStringContainsString('We build things people love.', $text);
        self::assertStringContainsString("Line one.\nLine two.", $text);
    }

    #[Test]
    public function excludesNonDefaultLanguageRows(): void
    {
        $text = $this->get(PageContentExtractor::class)->extractText([1]);

        self::assertStringNotContainsString('Willkommen', $text);
        self::assertStringNotContainsString('Nur auf Deutsch', $text);
    }

    #[Test]
    public function joinsMultiplePagesWithATitleSeparator(): void
    {
        $text = $this->get(PageContentExtractor::class)->extractText([1, 2]);

        self::assertStringContainsString('--- About Us ---', $text);
        self::assertStringContainsString('--- Contact ---', $text);
        self::assertStringContainsString('Drop us a line any time.', $text);
    }

    #[Test]
    public function returnsEmptyStringForAPageWithNoContent(): void
    {
        self::assertSame('', $this->get(PageContentExtractor::class)->extractText([999]));
    }

    #[Test]
    public function returnsEmptyStringForAnEmptyPageList(): void
    {
        self::assertSame('', $this->get(PageContentExtractor::class)->extractText([]));
    }

    #[Test]
    public function reportsFallbackAsTheSourceWhenNoSiteIsConfigured(): void
    {
        // None of these fixture pages have a real site configuration, so
        // FrontendPageRenderer can never succeed for them: every page with
        // content must report 'fallback', not 'rendered'.
        $result = $this->get(PageContentExtractor::class)->extractTextWithSources([1, 2]);

        self::assertSame('fallback', $result['sources'][1]);
        self::assertSame('fallback', $result['sources'][2]);
        self::assertStringContainsString('Welcome', $result['text']);
    }

    #[Test]
    public function reportsNoneAsTheSourceForAPageWithNoContentAtAll(): void
    {
        $result = $this->get(PageContentExtractor::class)->extractTextWithSources([999]);

        self::assertSame('none', $result['sources'][999]);
        self::assertSame('', $result['text']);
    }

    #[Test]
    public function onlyExtractsFieldsActuallyUsedByTheRowsOwnCType(): void
    {
        // CType "header" has no `bodytext` in its own showitem (it's a
        // heading-only element type), unlike the default "text" CType,
        // which is what every other fixture row in this test implicitly
        // uses. A stray/leftover bodytext value on a "header" row must not
        // be extracted, since the Schema API says this CType doesn't use it.
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 3, 'pid' => 0, 'title' => 'Heading Only']);

        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 5,
            'pid' => 3,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'CType' => 'header',
            'header' => 'A heading',
            'subheader' => 'A subheading',
            'bodytext' => 'Stray bodytext this CType never displays.',
        ]);

        $text = $this->get(PageContentExtractor::class)->extractText([3]);

        self::assertStringContainsString('A heading', $text);
        self::assertStringContainsString('A subheading', $text);
        self::assertStringNotContainsString('Stray bodytext', $text);
    }

    #[Test]
    public function fallsBackToTheHistoricalFieldsForACTypeWithNoMatchingSchema(): void
    {
        // A CType with no `types` entry at all (an uninstalled extension's
        // leftover row, or simply invalid data) can't be resolved via the
        // Schema API: the historical header/subheader/bodytext trio must
        // still be tried rather than extracting nothing.
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 4, 'pid' => 0, 'title' => 'Orphaned Element']);

        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => 6,
            'pid' => 4,
            'sys_language_uid' => 0,
            'colPos' => 0,
            'sorting' => 1,
            'CType' => 'nonexistent_ctype_from_an_uninstalled_extension',
            'header' => 'Still a heading',
            'bodytext' => 'Still body copy.',
        ]);

        $text = $this->get(PageContentExtractor::class)->extractText([4]);

        self::assertStringContainsString('Still a heading', $text);
        self::assertStringContainsString('Still body copy.', $text);
    }
}
