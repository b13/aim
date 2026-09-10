<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Service;

use B13\Aim\Service\PageContentExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The field resolution used on TYPO3 12.4, where there is no Schema API.
 * Exercised directly rather than through an extraction run, because the
 * production path picks it only when TcaSchemaFactory is absent, which cannot
 * be arranged on the version this suite usually runs on.
 *
 * What it has to get right: only the fields the CType displays, only the ones
 * that carry prose, and the type read through the CType's own
 * columnsOverrides, which is what the Schema API's sub-schemas do on v13+.
 */
final class PageContentFallbackFieldsTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $tcaBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'subheader' => ['config' => ['type' => 'input']],
                'bodytext' => ['config' => ['type' => 'text']],
                'header_link' => ['config' => ['type' => 'link']],
                'date' => ['config' => ['type' => 'datetime']],
                'assets' => ['config' => ['type' => 'file']],
                'pi_flexform' => ['config' => ['type' => 'flex']],
                'tx_vendor_quote' => ['config' => ['type' => 'text']],
                'tx_vendor_author' => ['config' => ['type' => 'input']],
            ],
            'palettes' => [
                'headers' => ['showitem' => 'header,header_link,--linebreak--,subheader,date'],
                'vendor' => ['showitem' => 'tx_vendor_quote,--linebreak--,tx_vendor_author'],
            ],
            'types' => [
                'header' => [
                    'showitem' => '--div--;General,--palette--;;headers',
                ],
                'textmedia' => [
                    'showitem' => '--div--;General,--palette--;;headers,bodytext;Text,assets,pi_flexform',
                    'columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]],
                ],
                'tx_vendor_quote' => [
                    'showitem' => '--palette--;;vendor,assets',
                ],
                'tx_vendor_odd' => [
                    // A third-party element that redefines a type for itself.
                    'showitem' => 'header,bodytext',
                    'columnsOverrides' => ['bodytext' => ['config' => ['type' => 'select']]],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->tcaBackup === null) {
            unset($GLOBALS['TCA']);
        } else {
            $GLOBALS['TCA'] = $this->tcaBackup;
        }
        parent::tearDown();
    }

    /**
     * A heading-only element: the palette contributes a link and a date next to
     * the two prose fields, and those two have no place in a prompt. bodytext is
     * not displayed at all here, which is the case that used to leak a value
     * left over from a CType switch.
     */
    #[Test]
    public function onlyTheProseFieldsOfTheDisplayedPaletteAreUsed(): void
    {
        self::assertSame(['header', 'subheader'], $this->resolve('header'));
    }

    #[Test]
    public function aTextElementAddsItsBodyAndStillDropsFilesAndFlexforms(): void
    {
        self::assertSame(['header', 'subheader', 'bodytext'], $this->resolve('textmedia'));
    }

    /**
     * The point of reading the type rather than a fixed list: a third-party
     * element's own prose fields are picked up, which is what the Schema API
     * path does on v13 and v14.
     */
    #[Test]
    public function aThirdPartyElementsOwnProseFieldsAreUsed(): void
    {
        self::assertSame(['tx_vendor_quote', 'tx_vendor_author'], $this->resolve('tx_vendor_quote'));
    }

    /**
     * columnsOverrides can redefine a type for one CType only, and the type
     * that counts is the redefined one.
     */
    #[Test]
    public function aTypeRedefinedForThisCTypeOnlyIsHonoured(): void
    {
        self::assertSame(['header'], $this->resolve('tx_vendor_odd'));
    }

    /**
     * A row left over from an uninstalled extension has no type definition, so
     * there is nothing to read and the historical trio is all there is.
     */
    #[Test]
    public function anUnknownCTypeKeepsTheHistoricalFields(): void
    {
        self::assertSame(['header', 'subheader', 'bodytext'], $this->resolve('tx_gone_away'));
    }

    /**
     * @return list<string>
     */
    private function resolve(string $cType): array
    {
        // Built without its constructor: the method under test touches neither
        // dependency, and FrontendPageRenderer is final so it cannot be doubled.
        $extractor = (new \ReflectionClass(PageContentExtractor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($extractor, 'displayedFallbackFieldsFor');

        return $method->invoke($extractor, $cType);
    }
}
