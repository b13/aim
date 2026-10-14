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
use B13\Aim\Provider\SymfonyAi\Event\ModifySymfonyAiHttpClientEvent;
use B13\Aim\Provider\SymfonyAi\SymfonyAiPlatformAdapter;
use B13\Aim\Tests\Unit\Provider\Fixtures\FactoryWithHttpClient;
use B13\Aim\Tests\Unit\Provider\Fixtures\FactoryWithoutHttpClient;
use B13\Aim\Tests\Unit\Provider\Fixtures\MockHttpClient;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The httpClient hook of the factory arguments: the event is only dispatched
 * for bridges whose factory declares an httpClient parameter, and a client set
 * by a listener is handed to that factory.
 */
final class HttpClientEventTest extends UnitTestCase
{
    #[Test]
    public function noEventIsDispatchedWhenTheFactoryHasNoHttpClientParameter(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');
        GeneralUtility::addInstance(EventDispatcherInterface::class, $dispatcher);

        $adapter = new SymfonyAiPlatformAdapter(FactoryWithoutHttpClient::class);

        self::assertSame(['apiKey' => 'sk-secret'], $this->buildArguments($adapter));

        // The dispatcher was never asked for, so it is still queued.
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function theEventIsDispatchedWhenTheFactoryHasAnHttpClientParameter(): void
    {
        $configuration = $this->configuration();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn(object $event): bool => $event instanceof ModifySymfonyAiHttpClientEvent
                    && $event->configuration === $configuration,
            ))
            ->willReturnArgument(0);
        GeneralUtility::addInstance(EventDispatcherInterface::class, $dispatcher);

        $adapter = new SymfonyAiPlatformAdapter(FactoryWithHttpClient::class);

        // Nobody listens, so no client is passed on.
        self::assertSame(['apiKey' => 'sk-secret'], $this->buildArguments($adapter, $configuration));
    }

    #[Test]
    public function theClientSetByAListenerIsPassedToTheFactory(): void
    {
        $client = new MockHttpClient();
        $dispatcher = new class ($client) implements EventDispatcherInterface {
            public function __construct(private readonly HttpClientInterface $client) {}

            public function dispatch(object $event): object
            {
                if ( $event instanceof ModifySymfonyAiHttpClientEvent) {
                    $event->setHttpClient($this->client);
                }
                return $event;
            }
        };
        GeneralUtility::addInstance(EventDispatcherInterface::class, $dispatcher);

        $adapter = new SymfonyAiPlatformAdapter(FactoryWithHttpClient::class);
        $arguments = $this->buildArguments($adapter);

        self::assertSame($client, $arguments['httpClient']);
        self::assertSame('sk-secret', $arguments['apiKey']);
    }

    private function configuration(): ProviderConfiguration
    {
        return new ProviderConfiguration(['endpoint' => '', 'api_key' => 'sk-secret']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildArguments(SymfonyAiPlatformAdapter $adapter, ?ProviderConfiguration $configuration = null): array
    {
        $method = new \ReflectionMethod(SymfonyAiPlatformAdapter::class, 'buildFactoryArguments');

        return $method->invoke($adapter, $configuration ?? $this->configuration());
    }
}
