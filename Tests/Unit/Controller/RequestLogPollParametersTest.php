<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The five-second auto-poll (request-log-poll.js) replaces the whole tbody
 * with whatever the poll endpoint returns, so every parameter it fails to
 * send is not "left unchanged" but reset to RequestLogDemand's default:
 * page 1, crdate desc, no filter. The filters, the pagination widget and
 * the sort arrow keep showing the user's own selection, so the swap is
 * silent. This suite runs no browser, so the JS module itself is inspected
 * as source (same approach as
 * Tests/Unit/Configuration/ModulesNavigationComponentSourceTest.php),
 * cross-checked against the filter names Filters.html actually renders.
 */
final class RequestLogPollParametersTest extends TestCase
{
    private const JS = __DIR__ . '/../../../Resources/Public/JavaScript/request-log-poll.js';
    private const FILTERS_TEMPLATE = __DIR__ . '/../../../Resources/Private/Partials/RequestLog/Filters.html';

    #[Test]
    public function thePollSendsEveryFilterTheFilterFormRenders(): void
    {
        $filterNames = $this->renderedFilterNames();
        self::assertContains('model_used', $filterNames, 'Sanity check on the template this test reads.');

        $source = $this->parameterCollectionSource();

        // A hardcoded name list must be complete; the alternative is a
        // selector that picks up every demand[*] field, including ones added
        // to Filters.html later.
        preg_match_all('/demand\[(\w+)\]/', $source, $matches);
        $enumerated = array_values(array_unique($matches[1]));
        if ($enumerated !== []) {
            self::assertSame(
                [],
                array_values(array_diff($filterNames, $enumerated)),
                'These filters are rendered but never polled, so the poll silently drops them.',
            );
            return;
        }

        self::assertStringContainsString(
            '[name^="demand["]',
            $source,
            'Neither every filter name is enumerated nor are they collected by a demand[*] selector.',
        );
    }

    /**
     * RequestLogController::demandToRouteParams() plus the page it appends:
     * the same three parameters a rebuilt listing URL carries.
     */
    #[Test]
    public function thePollSendsTheCurrentSortingAndPage(): void
    {
        $source = $this->parameterCollectionSource();

        foreach (['orderField', 'orderDirection'] as $parameter) {
            self::assertStringContainsString(
                $parameter,
                $source,
                sprintf('%s is not polled, so every refresh reverts to the default sorting.', $parameter),
            );
        }

        self::assertMatchesRegularExpression(
            '/params(\.page\b|\[[\'"]page[\'"]\])/',
            $source,
            'The page is not polled, so every refresh drops the user back to page 1.',
        );
        self::assertStringContainsString(
            'paginator-target-page',
            $source,
            'The current page has to come from the paginator markup that renders it.',
        );
    }

    /**
     * The user can type into the paginator input without submitting it, and
     * the value property follows that typing while the attribute stays at the
     * rendered page. Polling the property would refresh a page the user is
     * not looking at.
     */
    #[Test]
    public function theCurrentPageIsReadFromTheRenderedAttribute(): void
    {
        self::assertMatchesRegularExpression(
            '/getAttribute\(\s*[\'"]value[\'"]\s*\)/',
            $this->parameterCollectionSource(),
        );
    }

    /**
     * @return list<string>
     */
    private function renderedFilterNames(): array
    {
        $template = file_get_contents(self::FILTERS_TEMPLATE);
        self::assertNotFalse($template);
        preg_match_all('/name="demand\[(\w+)\]"/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Everything from #getFilterParams() onwards, so the row renderers above
     * it (which mention filter-ish field names of their own) cannot satisfy
     * these assertions by accident.
     */
    private function parameterCollectionSource(): string
    {
        $source = file_get_contents(self::JS);
        self::assertNotFalse($source);
        $offset = strpos($source, '#getFilterParams() {');
        self::assertNotFalse($offset, 'The poll no longer has a #getFilterParams().');

        return substr($source, $offset);
    }
}
