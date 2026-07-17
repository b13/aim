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

use B13\Aim\Domain\Model\ProviderConfiguration;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;

/**
 * Wraps a streaming generator and yields only text chunks.
 *
 * Usage metadata is captured for deferred logging after the stream
 * completes; tool-call deltas are collected and exposed via
 * getToolCalls() once the stream is exhausted (they never yield as
 * text). Accumulates the full response text.
 */
class StreamChunkIterator implements \IteratorAggregate
{
    private string $accumulated = '';
    private ?AiUsageStatistics $usage = null;
    private bool $exhausted = false;

    /** @var list<ToolCall> */
    private array $toolCalls = [];

    /**
     * @param \Generator $generator The streaming generator from the provider
     * @param ProviderConfiguration $configuration For cost calculation
     * @param \Closure|null $onComplete Called with (AiUsageStatistics, string $fullContent) when stream ends
     * @param \Closure|null $onToolCallStart Called with (string $id, string $name) as soon as the
     *                                       model starts emitting a tool call — arguments are not
     *                                       known yet at that point
     */
    public function __construct(
        private readonly \Generator $generator,
        private readonly ProviderConfiguration $configuration,
        private readonly ?\Closure $onComplete = null,
        private ?\Closure $onToolCallStart = null,
    ) {}

    /**
     * Attach the tool-call-start callback after construction — providers
     * build this iterator internally, so consumers set the hook on the
     * response's iterator before consuming the stream.
     */
    public function setOnToolCallStart(?\Closure $onToolCallStart): void
    {
        $this->onToolCallStart = $onToolCallStart;
    }

    public function getIterator(): \Generator
    {
        try {
            foreach ($this->generator as $chunk) {
                if (is_string($chunk)) {
                    $this->accumulated .= $chunk;
                    yield $chunk;
                    continue;
                }
                // Symfony AI yields Stringable TextDelta objects, not raw strings
                if ($chunk instanceof \Stringable) {
                    $text = (string)$chunk;
                    $this->accumulated .= $text;
                    yield $text;
                    continue;
                }
                if ($chunk instanceof ToolCallStart) {
                    if ($this->onToolCallStart !== null) {
                        ($this->onToolCallStart)($chunk->getId(), $chunk->getName());
                    }
                    continue;
                }
                if ($chunk instanceof ToolCallComplete) {
                    foreach ($chunk->getToolCalls() as $call) {
                        $this->toolCalls[] = new ToolCall(
                            $call->getId(),
                            $call->getName(),
                            $call->getArguments() === []
                                ? '{}'
                                : json_encode($call->getArguments(), JSON_THROW_ON_ERROR),
                        );
                    }
                    continue;
                }
                // Capture TokenUsage objects from the stream
                if (is_object($chunk) && method_exists($chunk, 'getPromptTokens')) {
                    $this->usage = new AiUsageStatistics(
                        promptTokens: $chunk->getPromptTokens() ?? 0,
                        completionTokens: $chunk->getCompletionTokens() ?? 0,
                        cost: $this->calculateCost($chunk),
                        cachedTokens: method_exists($chunk, 'getCachedTokens') ? ($chunk->getCachedTokens() ?? 0) : 0,
                        reasoningTokens: method_exists($chunk, 'getThinkingTokens') ? ($chunk->getThinkingTokens() ?? 0) : 0,
                    );
                }
            }
        } finally {
            $this->exhausted = true;
            if ($this->onComplete !== null) {
                ($this->onComplete)($this->getUsage(), $this->accumulated);
            }
        }
    }

    public function getAccumulatedContent(): string
    {
        return $this->accumulated;
    }

    /**
     * Tool calls the model requested during the stream. Complete only
     * once the stream is exhausted.
     *
     * @return list<ToolCall>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function getUsage(): AiUsageStatistics
    {
        return $this->usage ?? new AiUsageStatistics();
    }

    public function isExhausted(): bool
    {
        return $this->exhausted;
    }

    private function calculateCost(object $tokenUsage): float
    {
        $promptTokens = $tokenUsage->getPromptTokens() ?? 0;
        $completionTokens = $tokenUsage->getCompletionTokens() ?? 0;
        $inputCost = (float)$this->configuration->get('input_token_cost', 0);
        $outputCost = (float)$this->configuration->get('output_token_cost', 0);
        return (($promptTokens / 1_000_000) * $inputCost)
            + (($completionTokens / 1_000_000) * $outputCost);
    }
}
