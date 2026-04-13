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

/**
 * Wraps a streaming generator and yields only text chunks.
 *
 * Non-text items (usage metadata, tool calls) are filtered out.
 * Accumulates the full response text and captures usage statistics
 * for deferred logging after the stream completes.
 */
class StreamChunkIterator implements \IteratorAggregate
{
    private string $accumulated = '';
    private ?AiUsageStatistics $usage = null;
    private bool $exhausted = false;

    /**
     * @param \Generator $generator The streaming generator from the provider
     * @param ProviderConfiguration $configuration For cost calculation
     * @param \Closure|null $onComplete Called with (AiUsageStatistics, string $fullContent) when stream ends
     */
    public function __construct(
        private readonly \Generator $generator,
        private readonly ProviderConfiguration $configuration,
        private readonly ?\Closure $onComplete = null,
    ) {}

    public function getIterator(): \Generator
    {
        try {
            foreach ($this->generator as $chunk) {
                if (is_string($chunk)) {
                    $this->accumulated .= $chunk;
                    yield $chunk;
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
