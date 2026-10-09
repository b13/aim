<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Middleware;

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\TextResponse;
use B13\Aim\Tests\Unit\Middleware\Fixtures\HttpClientMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers the example in Documentation/HttpClient.md.
 */
final class HttpClientMiddlewareTest extends TestCase
{
    private function createConfig(): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ]);
    }

    /**
     * @return array{0: TextResponse, 1: ProviderConfiguration}
     */
    private function process(ProviderConfiguration $config): array
    {
        $response = new TextResponse('hello', new AiUsageStatistics());
        $seen = null;
        $next = new AiMiddlewareHandler(static function ($request, $provider, ProviderConfiguration $configuration) use ($response, &$seen) {
            $seen = $configuration;
            return $response;
        });

        $result = (new HttpClientMiddleware())->process(
            new TextGenerationRequest(configuration: $config, prompt: 'Hi'),
            $this->createMock(AiProviderInterface::class),
            $config,
            $next
        );

        return [$result, $seen];
    }

    #[Test]
    public function runsEarlyInThePipeline(): void
    {
        $attributes = (new \ReflectionClass(HttpClientMiddleware::class))->getAttributes(AsAiMiddleware::class);

        self::assertCount(1, $attributes);
        self::assertSame(900, $attributes[0]->newInstance()->priority);
    }

    #[Test]
    public function createsEventSourceHttpClientWhenNoneIsSet(): void
    {
        $config = $this->createConfig();
        self::assertNull($config->httpClient);

        [, $seen] = $this->process($config);

        self::assertInstanceOf(EventSourceHttpClient::class, $seen->httpClient);
    }

    #[Test]
    public function passesTheConfigurationAndResponseThroughTheChain(): void
    {
        $config = $this->createConfig();

        [$result, $seen] = $this->process($config);

        self::assertSame('hello', $result->content);
        self::assertSame($config, $seen);
    }

    #[Test]
    public function appliesTimeoutOf900SecondsToAnExistingClient(): void
    {
        $receivedOptions = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;
            return new MockResponse('{}');
        });
        $config = $this->createConfig();
        $config->httpClient = $mock;

        $this->process($config);
        $config->httpClient->request('GET', 'https://example.com/')->getContent();

        self::assertSame(900.0, (float)$receivedOptions['timeout']);
    }

    #[Test]
    public function keepsOtherOptionsOfAnExistingClient(): void
    {
        $receivedOptions = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;
            return new MockResponse('{}');
        }, 'https://example.com');
        $config = $this->createConfig();
        $config->httpClient = $mock->withOptions(['proxy' => 'socks5://127.0.0.1:9050']);

        $this->process($config);
        $config->httpClient->request('GET', '/path')->getContent();

        self::assertSame(900.0, (float)$receivedOptions['timeout']);
        self::assertSame('socks5://127.0.0.1:9050', $receivedOptions['proxy']);
    }

    #[Test]
    public function doesNotTouchTheOriginalClientInstance(): void
    {
        $receivedOptions = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$receivedOptions) {
            $receivedOptions = $options;
            return new MockResponse('{}');
        });
        $config = $this->createConfig();
        $config->httpClient = $mock;

        $this->process($config);

        self::assertNotSame($mock, $config->httpClient);
        $mock->request('GET', 'https://example.com/')->getContent();
        self::assertNotSame(900.0, (float)($receivedOptions['timeout'] ?? 0));
    }

    #[Test]
    public function mockClientPreventsRealHttpRequests(): void
    {
        $requested = [];
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$requested) {
            $requested[] = $method . ' ' . $url;
            return new MockResponse('{"ok":true}');
        });
        $config = $this->createConfig();
        $config->httpClient = $mock;

        $this->process($config);
        $content = $config->httpClient->request('POST', 'https://api.example.invalid/v1/chat')->getContent();

        self::assertSame('{"ok":true}', $content);
        self::assertSame(['POST https://api.example.invalid/v1/chat'], $requested);
    }
}
