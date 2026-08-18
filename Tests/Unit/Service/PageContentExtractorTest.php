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
 * cleanRenderedHtml() is pure HTML-string-in/text-out logic with no DB/
 * service dependencies, so it's unit-testable in isolation via reflection
 * (mirroring GradingServiceTest's invokeParser() convention). Mo need for
 * the full functional-test weight PageContentExtractorTest (functional)
 * already carries for the tt_content fallback path.
 */
final class PageContentExtractorTest extends TestCase
{
    #[Test]
    public function dropsWhitespaceOnlyLinesFromRenderedHtml(): void
    {
        // A typical theme's layout/grid <div>s flatten to text nodes that
        // are pure whitespace — these must not survive as "blank lines"
        // diluting the real content into a wall of near-empty lines.
        $html = '<html><body><div>   </div><h1>Real Heading</h1><div>' . "\n \n" . '</div><p>Real paragraph text.</p></body></html>';

        self::assertSame("Real Heading\nReal paragraph text.", $this->invokeCleanRenderedHtml($html));
    }

    #[Test]
    public function stripsScriptStyleNavHeaderFooterContent(): void
    {
        $html = '<html><body><nav>Nav links</nav><header>Site header</header><script>var x=1;</script>'
            . '<style>.a{}</style><main>Actual content</main><footer>Footer text</footer></body></html>';

        self::assertSame('Actual content', $this->invokeCleanRenderedHtml($html));
    }

    #[Test]
    public function collapsesRepeatedInlineWhitespaceWithinALine(): void
    {
        $html = '<html><body><p>Too   much     space</p></body></html>';

        self::assertSame('Too much space', $this->invokeCleanRenderedHtml($html));
    }

    #[Test]
    public function returnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', $this->invokeCleanRenderedHtml(''));
    }

    private function invokeCleanRenderedHtml(string $html): string
    {
        $extractor = (new \ReflectionClass(PageContentExtractor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($extractor, 'cleanRenderedHtml');
        return $method->invoke($extractor, $html);
    }
}
