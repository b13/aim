<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Backend;

use B13\Aim\Backend\SortUrlBuilder;
use B13\Aim\Domain\Repository\SortableDemandInterface;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;

final class SortUrlBuilderTest extends TestCase
{
    #[Test]
    public function buildProducesAscAndDescUrlsPerFieldPreservingActiveFilters(): void
    {
        $demand = new class implements SortableDemandInterface {
            public function getOrderField(): string
            {
                return 'title';
            }

            public function getOrderDirection(): string
            {
                return 'asc';
            }

            public function getParameters(): array
            {
                return ['title' => 'foo'];
            }

            public static function getOrderFields(): array
            {
                return ['title', 'cost'];
            }
        };

        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn(string $route, array $params) => new Uri($route . '?' . http_build_query($params)),
        );

        $sortUrls = (new SortUrlBuilder($uriBuilder))->build($demand, 'aim_test_route');

        self::assertSame(['title', 'cost'], array_keys($sortUrls));

        // Active field: reflects the current direction, and the filter param survives.
        self::assertTrue($sortUrls['title']['active']);
        self::assertSame('asc', $sortUrls['title']['direction']);
        self::assertStringContainsString('demand%5Btitle%5D=foo', $sortUrls['title']['ascUrl']);
        self::assertStringContainsString('orderField=title', $sortUrls['title']['ascUrl']);
        self::assertStringContainsString('orderDirection=asc', $sortUrls['title']['ascUrl']);
        self::assertStringContainsString('orderDirection=desc', $sortUrls['title']['descUrl']);

        // Inactive field: no direction to report, but still gets both links, filter still preserved.
        self::assertFalse($sortUrls['cost']['active']);
        self::assertSame('', $sortUrls['cost']['direction']);
        self::assertStringContainsString('demand%5Btitle%5D=foo', $sortUrls['cost']['ascUrl']);
        self::assertStringContainsString('orderField=cost', $sortUrls['cost']['ascUrl']);
    }

    #[Test]
    public function additionalParametersSurviveInEverySortLinkUnnamespaced(): void
    {
        // e.g. a page-tree module's selected `id`, sorting must not drop it.
        $demand = new class implements SortableDemandInterface {
            public function getOrderField(): string
            {
                return 'title';
            }

            public function getOrderDirection(): string
            {
                return 'asc';
            }

            public function getParameters(): array
            {
                return [];
            }

            public static function getOrderFields(): array
            {
                return ['title'];
            }
        };

        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn(string $route, array $params) => new Uri($route . '?' . http_build_query($params)),
        );

        $sortUrls = (new SortUrlBuilder($uriBuilder))->build($demand, 'aim_test_route', ['id' => 42]);

        self::assertStringContainsString('id=42', $sortUrls['title']['ascUrl']);
        self::assertStringContainsString('id=42', $sortUrls['title']['descUrl']);
    }
}
