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

use B13\Aim\Provider\SymfonyAi\SymfonyAiPlatformAdapter;
use B13\Aim\Request\Message\AssistantMessage;
use B13\Aim\Request\Message\ToolMessage;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\ToolResult;
use B13\Aim\Response\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Message\AssistantMessage as SymfonyAssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;

final class SymfonyAiPlatformAdapterTest extends TestCase
{
    public static function maxTokensKeyProvider(): \Generator
    {
        yield 'OpenAI Responses API' => [
            'Symfony\\AI\\Platform\\Bridge\\OpenAi\\PlatformFactory',
            'max_output_tokens',
        ];
        yield 'OpenResponses bridge' => [
            'Symfony\\AI\\Platform\\Bridge\\OpenResponses\\PlatformFactory',
            'max_output_tokens',
        ];
        yield 'Gemini uses camelCase per Google REST API' => [
            'Symfony\\AI\\Platform\\Bridge\\Gemini\\PlatformFactory',
            'maxOutputTokens',
        ];
        yield 'Anthropic Messages API' => [
            'Symfony\\AI\\Platform\\Bridge\\Anthropic\\PlatformFactory',
            'max_tokens',
        ];
        yield 'Mistral' => [
            'Symfony\\AI\\Platform\\Bridge\\Mistral\\PlatformFactory',
            'max_tokens',
        ];
        yield 'Ollama' => [
            'Symfony\\AI\\Platform\\Bridge\\Ollama\\PlatformFactory',
            'max_tokens',
        ];
        yield 'unknown bridge falls back to max_tokens' => [
            'Acme\\AiBridge\\PlatformFactory',
            'max_tokens',
        ];
    }

    #[Test]
    #[DataProvider('maxTokensKeyProvider')]
    public function resolveMaxTokensKeyMapsBridgeToCorrectOptionName(string $factoryClass, string $expectedKey): void
    {
        self::assertSame($expectedKey, SymfonyAiPlatformAdapter::resolveMaxTokensKey($factoryClass));
    }

    #[Test]
    public function buildOptionsSendsTemperatureOnlyWhenTheCallerSetOne(): void
    {
        $adapter = new SymfonyAiPlatformAdapter('Symfony\\AI\\Platform\\Bridge\\Anthropic\\PlatformFactory');
        $buildOptions = new \ReflectionMethod($adapter, 'buildOptions');

        self::assertSame(['max_tokens' => 1000], $buildOptions->invoke($adapter, 1000, null));
        self::assertSame(['max_tokens' => 1000, 'temperature' => 0.0], $buildOptions->invoke($adapter, 1000, 0.0));
    }

    #[Test]
    public function invokeRetriesWithoutTemperatureWhenTheModelRejectsIt(): void
    {
        $adapter = new SymfonyAiPlatformAdapter('Symfony\\AI\\Platform\\Bridge\\Anthropic\\PlatformFactory');
        $sentOptions = [];
        $platform = $this->createPlatform(static function (array $options) use (&$sentOptions): TextResult {
            $sentOptions[] = $options;
            if (array_key_exists('temperature', $options)) {
                throw new BadRequestException('`temperature` is deprecated for this model.');
            }
            return new TextResult('ok');
        });

        $result = (new \ReflectionMethod($adapter, 'invoke'))
            ->invoke($adapter, $platform, 'claude-sonnet-5', new MessageBag(Message::ofUser('Hi')), ['max_tokens' => 10, 'temperature' => 0.7]);

        self::assertSame('ok', $result->asText());
        self::assertCount(2, $sentOptions);
        self::assertSame(['max_tokens' => 10], $sentOptions[1]);
    }

    #[Test]
    public function invokeDoesNotRetryOtherBadRequests(): void
    {
        $adapter = new SymfonyAiPlatformAdapter('Symfony\\AI\\Platform\\Bridge\\Anthropic\\PlatformFactory');
        $calls = 0;
        $platform = $this->createPlatform(static function () use (&$calls): TextResult {
            $calls++;
            throw new BadRequestException('max_tokens: must be greater than 0');
        });

        try {
            (new \ReflectionMethod($adapter, 'invoke'))
                ->invoke($adapter, $platform, 'acme-model', new MessageBag(Message::ofUser('Hi')), ['max_tokens' => 0, 'temperature' => 0.7]);
            self::fail('BadRequestException expected');
        } catch (BadRequestException) {
        }
        self::assertSame(1, $calls);
    }

    /**
     * @param \Closure(array<string, mixed>): TextResult $convert
     */
    private function createPlatform(\Closure $convert): ProviderInterface
    {
        $converter = $this->createMock(ResultConverterInterface::class);
        $converter->method('convert')->willReturnCallback(
            static fn(InMemoryRawResult $raw, array $options): TextResult => $convert($options),
        );
        $platform = $this->createMock(ProviderInterface::class);
        $platform->method('invoke')->willReturnCallback(
            static fn(string $model, MessageBag $input, array $options): DeferredResult => new DeferredResult($converter, new InMemoryRawResult(), $options),
        );
        return $platform;
    }

    #[Test]
    public function buildMessageBagMapsAssistantToolCallsToNativeSymfonyParts(): void
    {
        $bag = $this->buildMessageBag([
            new UserMessage('What is the weather in Berlin?'),
            new AssistantMessage('Let me check.', [
                new ToolCall('call_1', 'get_weather', '{"city":"Berlin"}'),
            ]),
        ]);

        $assistant = $bag->getMessages()[1];
        self::assertInstanceOf(SymfonyAssistantMessage::class, $assistant);
        self::assertTrue($assistant->hasToolCalls());
        $toolCall = $assistant->getToolCalls()[0];
        self::assertSame('call_1', $toolCall->getId());
        self::assertSame('get_weather', $toolCall->getName());
        self::assertSame(['city' => 'Berlin'], $toolCall->getArguments());
    }

    #[Test]
    public function buildMessageBagMapsToolMessageToToolCallMessageWithResolvedName(): void
    {
        $bag = $this->buildMessageBag([
            new UserMessage('What is the weather in Berlin?'),
            new AssistantMessage('', [
                new ToolCall('call_1', 'get_weather', '{"city":"Berlin"}'),
            ]),
            new ToolMessage('{"temperature":21}', 'call_1'),
        ]);

        $toolMessage = $bag->getMessages()[2];
        self::assertInstanceOf(ToolCallMessage::class, $toolMessage);
        self::assertSame('call_1', $toolMessage->getToolCall()->getId());
        self::assertSame('get_weather', $toolMessage->getToolCall()->getName());
        self::assertSame('{"temperature":21}', $toolMessage->asText());
    }

    #[Test]
    public function buildMessageBagAppendsToolResultsAsToolCallMessages(): void
    {
        $bag = $this->buildMessageBag(
            [
                new UserMessage('What is the weather in Berlin?'),
                new AssistantMessage('', [
                    new ToolCall('call_1', 'get_weather', '{"city":"Berlin"}'),
                ]),
            ],
            [new ToolResult('call_1', 'get_weather', '{"temperature":21}')],
        );

        $messages = $bag->getMessages();
        self::assertCount(3, $messages);
        $toolMessage = $messages[2];
        self::assertInstanceOf(ToolCallMessage::class, $toolMessage);
        self::assertSame('call_1', $toolMessage->getToolCall()->getId());
        self::assertSame('get_weather', $toolMessage->getToolCall()->getName());
        self::assertSame('{"temperature":21}', $toolMessage->asText());
    }

    #[Test]
    public function buildMessageBagFlattensToolMessagesWithoutNativeToolProtocol(): void
    {
        // Requests without a tools option (conversation, text) must not emit
        // native tool blocks — providers reject or blank a tool exchange when
        // no tools are defined.
        $bag = $this->buildMessageBag(
            [
                new UserMessage('What is the weather in Berlin?'),
                new AssistantMessage('Let me check.', [
                    new ToolCall('call_1', 'get_weather', '{"city":"Berlin"}'),
                ]),
                new ToolMessage('{"temperature":21}', 'call_1'),
            ],
            nativeToolProtocol: false,
        );

        $messages = $bag->getMessages();
        $assistant = $messages[1];
        self::assertInstanceOf(SymfonyAssistantMessage::class, $assistant);
        self::assertFalse($assistant->hasToolCalls());
        self::assertNotInstanceOf(ToolCallMessage::class, $messages[2]);
    }

    /**
     * @param list<\B13\Aim\Request\Message\AbstractMessage> $messages
     * @param list<ToolResult> $toolResults
     */
    private function buildMessageBag(array $messages, array $toolResults = [], bool $nativeToolProtocol = true): MessageBag
    {
        $adapter = new SymfonyAiPlatformAdapter('Symfony\\AI\\Platform\\Bridge\\Anthropic\\PlatformFactory');
        $method = new \ReflectionMethod($adapter, 'buildMessageBag');

        return $method->invoke($adapter, $messages, '', $nativeToolProtocol, $toolResults);
    }
}
