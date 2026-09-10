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

use B13\Aim\Provider\SymfonyAi\SymfonyAiPlatformAdapter;
use B13\Aim\Request\ToolDefinition;
use B13\Aim\Response\ToolCall;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AiM hands tool calls back for the consumer to execute, so an undeclared name
 * must not reach them.
 */
final class UndeclaredToolCallTest extends TestCase
{
    #[Test]
    public function aCallNamingAToolThatWasNeverOfferedIsDropped(): void
    {
        $kept = new ToolCall('call_1', 'getWeather', '{"city":"Berlin"}');
        $undeclared = new ToolCall('call_2', 'deleteAllRecords', '{}');

        $result = $this->filter([$kept, $undeclared], [new ToolDefinition('getWeather', 'Weather')]);

        self::assertSame([$kept], $result);
    }

    #[Test]
    public function everyCallIsDroppedWhenTheRequestDeclaredNoToolsAtAll(): void
    {
        $result = $this->filter([new ToolCall('call_1', 'anything', '{}')], []);

        self::assertSame([], $result);
    }

    #[Test]
    public function declaredCallsPassThroughUntouchedIncludingTheirArguments(): void
    {
        // Arguments are provider-shaped JSON and validating them is the
        // consumer's job; dropping a call over a schema quibble would lose a
        // legitimate one.
        $call = new ToolCall('call_1', 'getWeather', '{"city":12345,"unexpected":true}');

        $result = $this->filter([$call], [new ToolDefinition('getWeather', 'Weather')]);

        self::assertSame([$call], $result);
    }

    /**
     * @param list<ToolCall> $toolCalls
     * @param list<ToolDefinition> $tools
     * @return list<ToolCall>
     */
    private function filter(array $toolCalls, array $tools): array
    {
        $adapter = new SymfonyAiPlatformAdapter('B13\\Aim\\Tests\\Unit\\Provider\\NotAFactory');
        $method = new \ReflectionMethod($adapter, 'rejectUndeclaredToolCalls');

        return $method->invoke($adapter, $toolCalls, $tools);
    }
}
