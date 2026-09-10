<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Provider\SymfonyAi;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Provider\SymfonyAi\SymfonyAiPlatformAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a bridge factory actually receives, for migrated and unmigrated rows
 * alike. This is the narrowest point the endpoint/credential split passes
 * through, so the whole contract is pinned here.
 */
final class FactoryArgumentsTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}>
     */
    public static function cases(): array
    {
        return [
            'endpoint-only bridge, migrated row' => [
                ['endpoint' => 'endpoint', 'apiKey' => null],
                ['endpoint' => 'http://localhost:11434', 'api_key' => ''],
                ['endpoint' => 'http://localhost:11434'],
            ],
            'endpoint-only bridge, unmigrated row' => [
                ['endpoint' => 'endpoint', 'apiKey' => null],
                ['endpoint' => '', 'api_key' => 'http://localhost:11434'],
                ['endpoint' => 'http://localhost:11434'],
            ],
            'endpoint-only bridge naming it hostUrl' => [
                ['endpoint' => 'hostUrl', 'apiKey' => null],
                ['endpoint' => 'http://localhost:1234', 'api_key' => ''],
                ['hostUrl' => 'http://localhost:1234'],
            ],
            'credential-only bridge' => [
                ['endpoint' => null, 'apiKey' => 'apiKey'],
                ['endpoint' => '', 'api_key' => 'sk-secret'],
                ['apiKey' => 'sk-secret'],
            ],
            'bridge accepting both, gateway with base url and token' => [
                ['endpoint' => 'endpoint', 'apiKey' => 'apiKey'],
                ['endpoint' => 'https://gw/v1', 'api_key' => 'sk-secret'],
                ['endpoint' => 'https://gw/v1', 'apiKey' => 'sk-secret'],
            ],
            'bridge accepting both, unmigrated row must not repeat the url as a credential' => [
                ['endpoint' => 'endpoint', 'apiKey' => 'apiKey'],
                ['endpoint' => '', 'api_key' => 'http://localhost:11434'],
                ['endpoint' => 'http://localhost:11434'],
            ],
            'bridge accepting both, credential only' => [
                ['endpoint' => 'endpoint', 'apiKey' => 'apiKey'],
                ['endpoint' => '', 'api_key' => 'sk-secret'],
                ['apiKey' => 'sk-secret'],
            ],
        ];
    }

    /**
     * @param array{endpoint: string|null, apiKey: string|null} $factory
     * @param array<string, string> $row
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('cases')]
    public function theFactoryReceivesTheRightArguments(array $factory, array $row, array $expected): void
    {
        $adapter = (new \ReflectionClass(SymfonyAiPlatformAdapter::class))->newInstanceWithoutConstructor();
        $this->set($adapter, 'endpointParam', $factory['endpoint'] ?? 'endpoint');
        $this->set($adapter, 'factoryAcceptsEndpoint', $factory['endpoint'] !== null);
        $this->set($adapter, 'factoryAcceptsApiKey', $factory['apiKey'] !== null);
        $this->set($adapter, 'factoryParam', $factory['endpoint'] !== null ? 'endpoint' : 'apiKey');

        $method = new \ReflectionMethod(SymfonyAiPlatformAdapter::class, 'buildFactoryArguments');

        self::assertSame($expected, $method->invoke($adapter, new ProviderConfiguration($row)));
    }

    private function set(object $adapter, string $property, mixed $value): void
    {
        (new \ReflectionProperty(SymfonyAiPlatformAdapter::class, $property))->setValue($adapter, $value);
    }
}
