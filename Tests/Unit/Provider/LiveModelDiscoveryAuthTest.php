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
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * A self-hosted host that wants a bearer token for its model list could not be
 * enumerated, so its model dropdown stayed empty.
 */
final class LiveModelDiscoveryAuthTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $capturedOptions = [];

    #[Test]
    public function aCredentialIsSentAsABearerToken(): void
    {
        $names = $this->discover('http://localhost:11434', 'sk-secret');

        self::assertSame(['llama3.2:latest'], $names);
        self::assertSame(['Authorization' => 'Bearer sk-secret'], $this->capturedOptions['headers'] ?? []);
    }

    #[Test]
    public function noCredentialMeansNoAuthorizationHeader(): void
    {
        $this->discover('http://localhost:11434', '');

        self::assertArrayNotHasKey('headers', $this->capturedOptions);
    }

    /**
     * An unmigrated row hands the same value as endpoint and credential. Sending
     * the URL as a bearer token would be pointless and would put it in a header
     * a proxy may log.
     */
    #[Test]
    public function theEndpointIsNeverSentAsItsOwnCredential(): void
    {
        $this->discover('http://localhost:11434', 'http://localhost:11434');

        self::assertArrayNotHasKey('headers', $this->capturedOptions);
    }

    /**
     * @return list<string>
     */
    private function discover(string $endpoint, string $credential): array
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn('{"data":[{"id":"llama3.2:latest"}]}');
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($body);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options) use ($response): ResponseInterface {
                $this->capturedOptions = $options;
                return $response;
            }
        );

        return (new LiveModelDiscovery($requestFactory, $this->createMock(CacheManager::class)))
            ->fetchModelNames($endpoint, $credential);
    }
}
