<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Response;

/**
 * Response from a tool-calling capable AI interaction.
 *
 * May contain text content, tool calls, or both. When tool calls are
 * present, the caller should execute the tools and feed the results
 * back via a new ToolCallingRequest with toolResults populated.
 */
class ToolCallingResponse extends TextResponse
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param StreamChunkIterator|null $streamIterator When streaming, text chunks yield
     *        here and tool calls are available via $streamIterator->getToolCalls()
     *        once the stream is exhausted
     */
    public function __construct(
        string $content,
        public readonly array $toolCalls = [],
        AiUsageStatistics $usage = new AiUsageStatistics(),
        array $rawResponse = [],
        array $errors = [],
        public readonly ?StreamChunkIterator $streamIterator = null,
    ) {
        parent::__construct($content, $usage, $rawResponse, $errors);
    }

    public function isStreaming(): bool
    {
        return $this->streamIterator !== null;
    }

    /**
     * Whether the model requests tool execution before continuing.
     * When streaming, only meaningful after the stream is exhausted.
     */
    public function requiresToolExecution(): bool
    {
        return $this->getToolCalls() !== [];
    }

    /**
     * @return list<ToolCall>
     */
    public function getToolCalls(): array
    {
        if ($this->streamIterator !== null) {
            return $this->streamIterator->getToolCalls();
        }
        return $this->toolCalls;
    }

    public function isSuccessful(): bool
    {
        return $this->errors === []
            && ($this->content !== '' || $this->requiresToolExecution() || $this->isStreaming());
    }
}
