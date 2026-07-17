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

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\RequestContext;
use B13\Aim\Middleware\RequestLoggingMiddleware;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use B13\Aim\Response\ToolCallingResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;

final class RequestLoggingMiddlewareTest extends TestCase
{
    private function createConfig(): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
            'privacy_level' => 'standard',
        ]);
    }

    #[Test]
    public function logsNonStreamingResponseSynchronously(): void
    {
        $repository = $this->createMock(RequestLogRepository::class);
        $repository->expects(self::once())->method('log')->willReturn(42);

        $middleware = new RequestLoggingMiddleware($repository, new NullLogger());
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi');
        $next = new AiMiddlewareHandler(static fn() => new TextResponse('hello'));

        $result = $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame('hello', $result->content);
        self::assertSame(42, $next->context->logUid);
    }

    #[Test]
    public function doesNotLogStreamingResponseSynchronously(): void
    {
        // A streaming response's content/usage are placeholders until the
        // caller drains the stream — logging now would write a phantom
        // zero-cost row. The repository must not be touched synchronously.
        $repository = $this->createMock(RequestLogRepository::class);
        $repository->expects(self::never())->method('log');

        $middleware = new RequestLoggingMiddleware($repository, new NullLogger());
        $config = $this->createConfig();
        $request = new ConversationRequest(
            configuration: $config,
            messages: [new UserMessage('Hi')],
            stream: true,
        );
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'chunk';
        })(), $config);
        $streamingResponse = new ConversationResponse('', streamIterator: $streamIterator);
        $next = new AiMiddlewareHandler(static fn() => $streamingResponse);

        $result = $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertTrue($result->isStreaming());
        self::assertNull($next->context->logUid);
    }

    #[Test]
    public function resolveStreamedResponseReconstructsRealContentAndUsageForConversation(): void
    {
        $config = $this->createConfig();
        $usageChunk = new class {
            public function getPromptTokens(): int
            {
                return 10;
            }

            public function getCompletionTokens(): int
            {
                return 20;
            }
        };
        $streamIterator = new StreamChunkIterator((function () use ($usageChunk): \Generator {
            yield 'Hello ';
            yield 'world';
            yield $usageChunk;
        })(), $config);
        // Drain the stream, simulating the controller sending chunks to the client.
        iterator_to_array($streamIterator, false);

        $response = new ConversationResponse('', streamIterator: $streamIterator);

        $middleware = new RequestLoggingMiddleware($this->createMock(RequestLogRepository::class), new NullLogger());
        $resolved = (new \ReflectionMethod($middleware, 'resolveStreamedResponse'))->invoke($middleware, $response, $streamIterator);

        self::assertInstanceOf(ConversationResponse::class, $resolved);
        self::assertSame('Hello world', $resolved->content);
        self::assertSame(10, $resolved->usage->promptTokens);
        self::assertSame(20, $resolved->usage->completionTokens);
    }

    #[Test]
    public function resolveStreamedResponseReconstructsToolCallsForToolCalling(): void
    {
        $config = $this->createConfig();
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield new ToolCallStart('call_1', 'get_weather');
            yield new ToolCallComplete([
                new SymfonyToolCall('call_1', 'get_weather', ['city' => 'Berlin']),
            ]);
        })(), $config);
        iterator_to_array($streamIterator, false);

        $response = new ToolCallingResponse('', streamIterator: $streamIterator);

        $middleware = new RequestLoggingMiddleware($this->createMock(RequestLogRepository::class), new NullLogger());
        $resolved = (new \ReflectionMethod($middleware, 'resolveStreamedResponse'))->invoke($middleware, $response, $streamIterator);

        self::assertInstanceOf(ToolCallingResponse::class, $resolved);
        self::assertCount(1, $resolved->getToolCalls());
        self::assertSame('get_weather', $resolved->getToolCalls()[0]->name);
    }

    #[Test]
    public function applyStreamedLogWritesTheRealAccumulatedContentOnceStreamIsConsumed(): void
    {
        // applyStreamedLog() is what the shutdown function registered by
        // deferLogRequest() actually calls — tested directly here since PHP
        // only invokes shutdown functions at the end of the whole test
        // process (same constraint GraderMiddlewareTest works under).
        $config = $this->createConfig();
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'The answer is 42.';
        })(), $config);
        iterator_to_array($streamIterator, false);

        $request = new ConversationRequest(
            configuration: $config,
            messages: [new UserMessage('What is the answer?')],
            stream: true,
        );
        $response = new ConversationResponse('', streamIterator: $streamIterator);

        $repository = $this->createMock(RequestLogRepository::class);
        $repository->expects(self::once())
            ->method('log')
            ->with(self::callback(static fn(array $data): bool => $data['response_content'] === 'The answer is 42.'
                && $data['success'] === 1))
            ->willReturn(7);

        $middleware = new RequestLoggingMiddleware($repository, new NullLogger());
        $context = new RequestContext();
        (new \ReflectionMethod($middleware, 'applyStreamedLog'))->invoke(
            $middleware,
            $request,
            $response,
            $streamIterator,
            $config,
            hrtime(true),
            $context,
        );

        self::assertSame(7, $context->logUid);
    }
}
