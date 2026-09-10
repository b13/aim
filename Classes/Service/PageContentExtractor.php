<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Gets a page's real body copy for feeding into VoiceCalibrationService as
 * an example-text source an editor doesn't have to hand-copy from the
 * frontend.
 *
 * Tries an actual frontend render first (FrontendPageRenderer, the only
 * way to see content a plugin renders dynamically, e.g. news teasers, form
 * text, which never lives in a tt_content column at all), and falls back
 * to reading tt_content rows directly (default-language only) whenever
 * rendering doesn't produce anything usable. The DB fallback has no such
 * dependency on the site being reachable/published, so it's a safe, fast
 * landing spot rather than a hard failure.
 *
 * The DB fallback reads whichever columns are actually text-bearing for
 * each row's own CType (see textBearingFieldNamesFor()), derived from the
 * Schema API rather than a fixed header/subheader/bodytext list, so a
 * third-party content element's own prose fields are picked up too, not
 * just core's default ones.
 */
final class PageContentExtractor
{
    private const MAX_ELEMENTS_PER_PAGE = 100;

    /**
     * The historical field set. On TYPO3 12.4 it is narrowed to the ones the
     * CType actually displays, see displayedFallbackFieldsFor(); it is taken
     * whole only for a CType nothing is known about, e.g. a row left over from
     * an uninstalled extension.
     */
    private const FALLBACK_FIELDS = ['header', 'subheader', 'bodytext'];

    /**
     * The TCA types that hold prose. Mirrors the filter the Schema API path
     * applies (TableColumnType::INPUT and ::TEXT); every other type is a
     * link, a date, a file reference, a relation or a flexform, and its raw
     * value has no place in a prompt.
     */
    private const TEXT_BEARING_TCA_TYPES = ['input', 'text'];

    /** @var array<string, list<string>> */
    private array $fieldNamesByCType = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FrontendPageRenderer $frontendPageRenderer,
    ) {}

    /**
     * @param list<int> $pageUids
     */
    public function extractText(array $pageUids): string
    {
        return $this->extractTextWithSources($pageUids)['text'];
    }

    /**
     * @param list<int> $pageUids
     * @return array{text: string, sources: array<int, 'rendered'|'fallback'|'none'>}
     */
    public function extractTextWithSources(array $pageUids): array
    {
        $sections = [];
        $sources = [];
        foreach ($pageUids as $pageUid) {
            $rendered = $this->extractRenderedPageText($pageUid);
            if ($rendered !== null) {
                $pageText = $rendered;
                $sources[$pageUid] = 'rendered';
            } else {
                $pageText = $this->extractPageText($pageUid);
                $sources[$pageUid] = $pageText !== '' ? 'fallback' : 'none';
            }
            if ($pageText === '') {
                continue;
            }
            $title = $this->resolvePageTitle($pageUid);
            $sections[] = ($title !== '' ? '--- ' . $title . " ---\n" : '') . $pageText;
        }
        return ['text' => implode("\n\n", $sections), 'sources' => $sources];
    }

    /**
     * Null means "couldn't render, or rendered to nothing usable", either
     * way the caller should fall back to extractPageText(), not treat it as
     * a hard failure for the whole page.
     */
    private function extractRenderedPageText(int $pageUid): ?string
    {
        $html = $this->frontendPageRenderer->renderPageHtml($pageUid);
        if ($html === null) {
            return null;
        }
        $text = $this->cleanRenderedHtml($html);
        return $text !== '' ? $text : null;
    }

    private function cleanRenderedHtml(string $html): string
    {
        $dom = new \DOMDocument();
        $previousLibxmlUseErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlUseErrors);
        if (!$loaded) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//nav|//header|//footer|//noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $rawText = $body !== null ? $body->textContent : $dom->textContent;
        $lines = preg_split('/\r\n|\r|\n/', $rawText ?? '') ?: [];
        $lines = array_map(
            static fn(string $line): string => trim(preg_replace('/[ \t]+/', ' ', $line) ?? ''),
            $lines,
        );
        $lines = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
        $text = implode("\n", $lines);
        return trim($text);
    }

    /**
     * Selects every column rather than a fixed list: which columns are
     * actually worth reading varies per row (see textBearingFieldNamesFor()),
     * so the field list can't be known until each row's CType is in hand.
     * Bounded to MAX_ELEMENTS_PER_PAGE rows, so the wider row shape this
     * pulls in isn't a meaningful cost.
     */
    private function extractPageText(int $pageUid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // No frontend user in a CLI or backend run.
        $queryBuilder->getRestrictions()->add(
            GeneralUtility::makeInstance(FrontendGroupRestriction::class, [0, -1]),
        );
        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->setMaxResults(self::MAX_ELEMENTS_PER_PAGE)
            ->executeQuery()
            ->fetchAllAssociative();

        $parts = [];
        foreach ($rows as $row) {
            foreach ($this->textBearingFieldNamesFor((string)($row['CType'] ?? '')) as $field) {
                $text = $this->cleanText((string)($row[$field] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Which tt_content columns are actually text-bearing for one specific
     * CType, derived from the Schema API's sub-schema field list (that
     * type's own `showitem`, not the table's full ~100-column definition),
     * restricted to plain input/text TCA field types so link/date/file/
     * flex/relation columns never pollute extracted content with non-prose
     * values (e.g. header_link's typolink target, or a flexform's raw XML).
     * This is what lets a third-party CType's own prose fields (a "quote"
     * element's quote/author, a custom teaser's summary, ...) get picked up
     * automatically, without this class needing to know about them ahead of
     * time: the historical header/subheader/bodytext trio is exactly what
     * this same filter yields for every core CType anyway.
     *
     * On TYPO3 12.4 there is no Schema API, and the CType's showitem answers
     * the same question less precisely, see displayedFallbackFieldsFor().
     * A CType with no matching sub-schema at all, e.g. a row left over from an
     * uninstalled extension, keeps the historical trio.
     *
     * Cached per CType (not per row): the schema is fixed for the lifetime
     * of the running process, unlike page/fragment data.
     *
     * @return list<string>
     */
    private function textBearingFieldNamesFor(string $cType): array
    {
        return $this->fieldNamesByCType[$cType] ??= $this->resolveTextBearingFieldNames($cType);
    }

    /**
     * @return list<string>
     */
    private function resolveTextBearingFieldNames(string $cType): array
    {
        if (!class_exists(TcaSchemaFactory::class)) {
            return $this->displayedFallbackFieldsFor($cType);
        }

        $tcaSchemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
        if (!$tcaSchemaFactory->has('tt_content')) {
            return self::FALLBACK_FIELDS;
        }

        $schema = $tcaSchemaFactory->get('tt_content');
        if (!$schema->hasSubSchema($cType)) {
            return self::FALLBACK_FIELDS;
        }

        // FieldCollection::getNames() doesn't exist on TYPO3 v13 (only
        // ArrayAccess/IteratorAggregate/Countable there) - iterating and
        // reading each field's own getName() works identically on v13/v14.
        $fieldNames = array_map(
            static fn(FieldTypeInterface $field): string => $field->getName(),
            iterator_to_array($schema->getSubSchema($cType)
                ->getFields(static fn(FieldTypeInterface $field): bool => $field->isType(TableColumnType::INPUT, TableColumnType::TEXT))),
        );

        return $fieldNames !== [] ? $fieldNames : self::FALLBACK_FIELDS;
    }

    /**
     * The 12.4 answer, where there is no Schema API: the CType's own showitem
     * says which fields it displays, and the TCA type of each of them says
     * whether it carries prose. Both halves are needed. Taking every displayed
     * field would put a typolink target or a date into the prompt, and taking
     * the historical header/subheader/bodytext trio unconditionally, which is
     * what this did before, extracted a heading-only element's leftover body
     * text, a value the frontend never shows.
     *
     * The type is read through the CType's own columnsOverrides first: a type
     * can be redefined per CType, and the Schema API's sub-schemas honour that
     * on v13 and v14, so this has to as well. Core does not redefine a type
     * that way on tt_content today, but a third-party element may.
     *
     * A CType with no type definition at all keeps the trio, since there is
     * nothing better to go on.
     *
     * @return list<string>
     */
    private function displayedFallbackFieldsFor(string $cType): array
    {
        $typeConfiguration = $GLOBALS['TCA']['tt_content']['types'][$cType] ?? null;
        if (!is_array($typeConfiguration) || !is_string($typeConfiguration['showitem'] ?? null)) {
            return self::FALLBACK_FIELDS;
        }

        $fields = [];
        foreach (self::displayedFieldNames($typeConfiguration['showitem']) as $fieldName) {
            if (in_array($this->effectiveFieldType($fieldName, $typeConfiguration), self::TEXT_BEARING_TCA_TYPES, true)) {
                $fields[] = $fieldName;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Every column named by a showitem string, including the ones a palette
     * pulls in. Structural entries (--div--, --palette--, --linebreak--) end up
     * in the list as their own name and are dropped by the type lookup, which
     * finds no column for them.
     *
     * @return list<string>
     */
    private static function displayedFieldNames(string $showitem): array
    {
        $names = [];
        foreach (GeneralUtility::trimExplode(',', $showitem, true) as $item) {
            $parts = GeneralUtility::trimExplode(';', $item);
            if ($parts[0] !== '--palette--') {
                $names[] = $parts[0];
                continue;
            }
            // --palette--;label;name and --palette--;;name both name it third.
            $palette = (string)($GLOBALS['TCA']['tt_content']['palettes'][$parts[2] ?? '']['showitem'] ?? '');
            foreach (GeneralUtility::trimExplode(',', $palette, true) as $paletteItem) {
                $names[] = GeneralUtility::trimExplode(';', $paletteItem)[0];
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $typeConfiguration
     */
    private function effectiveFieldType(string $fieldName, array $typeConfiguration): string
    {
        $overridden = $typeConfiguration['columnsOverrides'][$fieldName]['config']['type'] ?? null;
        if (is_string($overridden)) {
            return $overridden;
        }

        return (string)($GLOBALS['TCA']['tt_content']['columns'][$fieldName]['config']['type'] ?? '');
    }

    private function cleanText(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $withLinebreaks = preg_replace('/<br\s*\/?>/i', "\n", $raw) ?? $raw;
        return trim(strip_tags($withLinebreaks));
    }

    private function resolvePageTitle(int $pageUid): string
    {
        $pageRow = BackendUtility::getRecord('pages', $pageUid);
        return $pageRow !== null ? BackendUtility::getRecordTitle('pages', $pageRow) : '';
    }
}
