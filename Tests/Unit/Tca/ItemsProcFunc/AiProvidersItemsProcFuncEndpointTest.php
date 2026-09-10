<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Tca\ItemsProcFunc;

use B13\Aim\Provider\LiveModelDiscovery;
use B13\Aim\Tca\ItemsProcFunc\AiProvidersItemsProcFunc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Live model discovery has to read the endpoint from the endpoint column, and
 * still cope with a row the split migration has not reached.
 */
final class AiProvidersItemsProcFuncEndpointTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function rows(): array
    {
        return [
            'migrated row' => [['endpoint' => 'http://localhost:11434', 'api_key' => ''], 'http://localhost:11434'],
            'migrated row with a credential alongside' => [['endpoint' => 'https://gw/v1', 'api_key' => 'sk-secret'], 'https://gw/v1'],
            'unmigrated row, url still in api_key' => [['endpoint' => '', 'api_key' => 'http://localhost:11434'], 'http://localhost:11434'],
            'hosted provider, nothing to discover' => [['endpoint' => '', 'api_key' => 'sk-secret'], ''],
            'nothing configured' => [['endpoint' => '', 'api_key' => ''], ''],
        ];
    }

    #[Test]
    #[DataProvider('rows')]
    public function theEndpointHandedToDiscoveryComesFromTheRightColumn(array $row, string $expected): void
    {
        $method = new \ReflectionMethod(AiProvidersItemsProcFunc::class, 'resolveDiscoveryEndpoint');
        $subject = (new \ReflectionClass(AiProvidersItemsProcFunc::class))->newInstanceWithoutConstructor();

        // The discovery service only decides whether a value looks like a URL.
        $discovery = new \ReflectionProperty(AiProvidersItemsProcFunc::class, 'liveModelDiscovery');
        $discovery->setValue($subject, new LiveModelDiscovery(
            (new \ReflectionClass(\TYPO3\CMS\Core\Http\RequestFactory::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(\TYPO3\CMS\Core\Cache\CacheManager::class))->newInstanceWithoutConstructor(),
        ));

        self::assertSame($expected, $method->invoke($subject, $row));
    }
}
