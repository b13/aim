<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Provider;

use B13\Aim\Provider\LiveModelDiscovery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Every render of a provider configuration form used to re-query the endpoint,
 * and an unreachable one cost the full connect timeout each time.
 */
final class LiveModelDiscoveryCacheTest extends TestCase
{
    private int $requests = 0;

    /** @var array<string, array{0: mixed, 1: int|null}> */
    private array $store = [];

    #[Test]
    public function theSecondLookupIsServedFromTheCache(): void
    {
        $subject = $this->subject('{"data":[{"id":"llama3.2"}]}');

        self::assertSame(['llama3.2'], $subject->fetchModelNames('http://localhost:11434'));
        self::assertSame(['llama3.2'], $subject->fetchModelNames('http://localhost:11434'));
        self::assertSame(1, $this->requests, 'The endpoint was queried twice.');
    }

    #[Test]
    public function aDifferentCredentialIsLookedUpSeparately(): void
    {
        $subject = $this->subject('{"data":[{"id":"llama3.2"}]}');

        $subject->fetchModelNames('http://localhost:11434', 'token-one');
        $subject->fetchModelNames('http://localhost:11434', 'token-two');

        self::assertSame(2, $this->requests, 'A changed credential has to be re-queried.');
    }

    #[Test]
    public function aDifferentEndpointIsLookedUpSeparately(): void
    {
        $subject = $this->subject('{"data":[{"id":"llama3.2"}]}');

        $subject->fetchModelNames('http://localhost:11434');
        $subject->fetchModelNames('http://localhost:1234');

        self::assertSame(2, $this->requests);
    }

    /**
     * The failure case is the expensive one, so it is cached too, but only
     * briefly: fixing the endpoint should not stay masked.
     */
    #[Test]
    public function anEmptyResultIsCachedForMuchLessTimeThanAFoundOne(): void
    {
        $found = $this->subject('{"data":[{"id":"llama3.2"}]}');
        $found->fetchModelNames('http://localhost:11434');
        $foundLifetime = array_values($this->store)[0][1];

        $this->store = [];
        $empty = $this->subject('{"data":[]}');
        $empty->fetchModelNames('http://localhost:9999');
        $emptyLifetime = array_values($this->store)[0][1];

        self::assertNotNull($foundLifetime);
        self::assertNotNull($emptyLifetime);
        self::assertLessThan($foundLifetime, $emptyLifetime);
    }

    /**
     * A pool registered but whose database table has not been created yet, which
     * is the state right after deploying and before the schema update.
     */
    #[Test]
    public function aCachePoolWithoutItsTableDoesNotStopDiscovery(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willThrowException(new \RuntimeException("Table 'db.cache_aim_models' doesn't exist"));
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);
        $subject = new LiveModelDiscovery($this->requestFactory('{"data":[{"id":"llama3.2"}]}'), $cacheManager);

        self::assertSame(['llama3.2'], $subject->fetchModelNames('http://localhost:11434'));
    }

    #[Test]
    public function aMissingCachePoolDoesNotStopDiscovery(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willThrowException(new NoSuchCacheException());
        $subject = new LiveModelDiscovery($this->requestFactory('{"data":[{"id":"llama3.2"}]}'), $cacheManager);

        self::assertSame(['llama3.2'], $subject->fetchModelNames('http://localhost:11434'));
    }

    private function subject(string $json): LiveModelDiscovery
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('has')->willReturn(false);
        $cache->method('get')->willReturnCallback(fn(string $id) => $this->store[$id][0] ?? false);
        $cache->method('set')->willReturnCallback(
            function (string $id, $data, array $tags = [], $lifetime = null): void {
                $this->store[$id] = [$data, $lifetime];
            }
        );
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        return new LiveModelDiscovery($this->requestFactory($json), $cacheManager);
    }

    private function requestFactory(string $json): RequestFactory
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn($json);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($body);

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(function () use ($response): ResponseInterface {
            $this->requests++;
            return $response;
        });

        return $factory;
    }
}
