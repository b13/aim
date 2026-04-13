<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Provider;

/**
 * Describes granular feature support of an AI provider beyond capability interfaces.
 *
 * While capabilities (VisionCapableInterface, etc.) declare what a provider can do,
 * features describe how well it does it and what constraints apply.
 */
final class ProviderFeatures
{
    /**
     * @param bool $supportsStructuredOutput Whether JSON Schema constrained output is supported
     * @param bool $supportsParallelToolCalls Whether multiple tool calls can be made in a single response
     * @param bool $supportsStreaming Whether streaming responses are supported
     * @param int $maxContextWindow Maximum input token window (0 = unknown)
     * @param int $maxOutputTokens Maximum output tokens per request (0 = unknown)
     * @param array<string, mixed> $extra Open-ended feature flags for extension-specific needs
     */
    public function __construct(
        public readonly bool $supportsStructuredOutput = false,
        public readonly bool $supportsParallelToolCalls = false,
        public readonly bool $supportsStreaming = false,
        public readonly int $maxContextWindow = 0,
        public readonly int $maxOutputTokens = 0,
        public readonly array $extra = [],
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            supportsStructuredOutput: (bool)($data['supportsStructuredOutput'] ?? false),
            supportsParallelToolCalls: (bool)($data['supportsParallelToolCalls'] ?? false),
            supportsStreaming: (bool)($data['supportsStreaming'] ?? false),
            maxContextWindow: (int)($data['maxContextWindow'] ?? 0),
            maxOutputTokens: (int)($data['maxOutputTokens'] ?? 0),
            extra: array_diff_key($data, array_flip([
                'supportsStructuredOutput',
                'supportsParallelToolCalls',
                'supportsStreaming',
                'maxContextWindow',
                'maxOutputTokens',
            ])),
        );
    }

    /**
     * Check for a named feature flag (including extra features).
     */
    public function has(string $feature): bool
    {
        return match ($feature) {
            'supportsStructuredOutput' => $this->supportsStructuredOutput,
            'supportsParallelToolCalls' => $this->supportsParallelToolCalls,
            'supportsStreaming' => $this->supportsStreaming,
            default => !empty($this->extra[$feature]),
        };
    }
}
