<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Response;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\ToolCall;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;

final class StreamChunkIteratorTest extends TestCase
{
    #[Test]
    public function yieldsOnlyTextAndCollectsToolCalls(): void
    {
        $iterator = new StreamChunkIterator(
            (function (): \Generator {
                yield new TextDelta('Let me check. ');
                yield new ToolCallStart('call_1', 'get_weather');
                yield new ToolInputDelta('call_1', 'get_weather', '{"city":');
                yield new ToolInputDelta('call_1', 'get_weather', '"Berlin"}');
                yield new ToolCallComplete([
                    new SymfonyToolCall('call_1', 'get_weather', ['city' => 'Berlin']),
                ]);
            })(),
            new ProviderConfiguration(['uid' => 1, 'ai_provider' => 'test']),
        );

        $chunks = iterator_to_array($iterator, false);

        self::assertSame(['Let me check. '], $chunks);
        self::assertSame('Let me check. ', $iterator->getAccumulatedContent());
        self::assertCount(1, $iterator->getToolCalls());
        $toolCall = $iterator->getToolCalls()[0];
        self::assertInstanceOf(ToolCall::class, $toolCall);
        self::assertSame('call_1', $toolCall->id);
        self::assertSame('get_weather', $toolCall->name);
        self::assertSame(['city' => 'Berlin'], $toolCall->getDecodedArguments());
    }

    #[Test]
    public function invokesOnToolCallStartBeforeArgumentsAreComplete(): void
    {
        $started = [];
        $iterator = new StreamChunkIterator(
            (function (): \Generator {
                yield new ToolCallStart('call_1', 'get_weather');
                yield new ToolCallComplete([
                    new SymfonyToolCall('call_1', 'get_weather', []),
                ]);
            })(),
            new ProviderConfiguration(['uid' => 1, 'ai_provider' => 'test']),
            null,
            static function (string $id, string $name) use (&$started): void {
                $started[] = [$id, $name];
            },
        );

        iterator_to_array($iterator, false);

        self::assertSame([['call_1', 'get_weather']], $started);
        self::assertSame('{}', $iterator->getToolCalls()[0]->arguments);
    }

    #[Test]
    public function plainTextStreamStillYieldsStringsAndHasNoToolCalls(): void
    {
        $iterator = new StreamChunkIterator(
            (function (): \Generator {
                yield 'Hello ';
                yield new TextDelta('world');
            })(),
            new ProviderConfiguration(['uid' => 1, 'ai_provider' => 'test']),
        );

        self::assertSame(['Hello ', 'world'], iterator_to_array($iterator, false));
        self::assertSame([], $iterator->getToolCalls());
    }
}
