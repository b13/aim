<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request\Message;

use B13\Aim\Response\ToolCall;

/**
 * A message from the AI assistant. Optionally carries tool calls
 * that need to be included in the message history for multi-turn
 * tool-calling conversations.
 */
final class AssistantMessage extends AbstractMessage
{
    /** @var list<ToolCall> */
    public readonly array $toolCalls;

    /**
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(string $content, array $toolCalls = [])
    {
        parent::__construct('assistant', $content);
        $this->toolCalls = $toolCalls;
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->toolCalls !== []) {
            $data['tool_calls'] = array_map(
                static fn(ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => $call->arguments,
                    ],
                ],
                $this->toolCalls,
            );
        }
        return $data;
    }
}
