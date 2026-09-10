<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Governance;

use B13\Aim\Governance\RateLimitCounter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * The counter sits in the dispatch path, so a cache problem must cost the limit,
 * never the request. A pool whose database table has not been created yet is the
 * state right after deploying and before the schema update.
 */
final class RateLimitCounterResilienceTest extends TestCase
{
    #[Test]
    public function anUnregisteredPoolDoesNotBreakTheRequest(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willThrowException(new NoSuchCacheException());

        self::assertSame(0, (new RateLimitCounter($cacheManager, new NullLogger()))->record(5));
    }

    #[Test]
    public function aPoolWithoutItsTableDoesNotBreakTheRequest(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willThrowException(new \RuntimeException("Table 'db.cache_aim_ratelimit' doesn't exist"));
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        self::assertSame(0, (new RateLimitCounter($cacheManager, new NullLogger()))->record(5));
    }

    #[Test]
    public function aWorkingPoolStillCounts(): void
    {
        $store = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturnCallback(function (string $id) use (&$store) {
            return $store[$id] ?? false;
        });
        $cache->method('set')->willReturnCallback(function (string $id, $data) use (&$store): void {
            $store[$id] = $data;
        });
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);
        $counter = new RateLimitCounter($cacheManager, new NullLogger());

        self::assertSame(1, $counter->record(5));
        self::assertSame(2, $counter->record(5));
    }

    /**
     * has() is a plain existence check on most backends, so the probe in
     * cache() passes and the write is where an unwritable cache directory
     * actually fails. Since a default limit ships, this method runs on every
     * authenticated request: throwing here would take the whole installation's
     * AI traffic down over a cache problem.
     */
    #[Test]
    public function aPoolThatCannotBeWrittenToDoesNotBreakTheRequest(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturn(false);
        $cache->method('set')->willThrowException(new \RuntimeException('The cache directory "/var/cache" is not writable'));
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        self::assertSame(0, (new RateLimitCounter($cacheManager, new NullLogger()))->record(5));
    }

    #[Test]
    public function aPoolThatCannotBeReadFromDoesNotBreakTheRequest(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willThrowException(new \RuntimeException("Table 'db.cache_aim_ratelimit' doesn't exist"));
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        self::assertSame(0, (new RateLimitCounter($cacheManager, new NullLogger()))->record(5));
    }
}
