<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Service;

use B13\Aim\Ai;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Request\ResponseFormat;
use B13\Aim\Utility\JsonExtractor;

/**
 * Derives a reusable tone-of-voice instruction + illustrative Q/A examples
 * from a pasted sample of real, on-brand copy, letting an editor calibrate a
 * tx_aim_prompt_fragment from existing content instead of hand-writing the
 * prompt/examples fields from scratch.
 *
 * Dispatches through the normal Ai proxy (unlike GradingService, which
 * bypasses the pipeline to avoid a DI cycle since it's invoked mid-pipeline
 * itself). This call is user-triggered, not a side effect of another
 * request, so it should be logged, cost-tracked, and governed exactly like
 * any other AiM request rather than exempted from those concerns.
 */
final class VoiceCalibrationService
{
    private const MIN_LENGTH = 40;
    // Public: SiteVoiceCalibrator shares this exact cap as the budget it
    // accumulates crawled pages up to, so a combined multi-page text never
    // arrives here already too long to begin with.
    public const MAX_LENGTH = 12000;

    /**
     * Strict JSON Schema, not ResponseFormat::json() (generic "json_object"):
     * OpenAI's Responses-API bridge (symfony/ai-open-responses-platform)
     * only translates the response_format option into its own `text.format`
     * shape when it sees a json_schema payload; a plain json_object one is
     * passed through untouched and OpenAI's Responses endpoint then rejects
     * "response_format" outright ("this parameter has moved to
     * 'text.format'"). json_schema is also valid on every other bridge this
     * extension talks to (both OpenAI APIs, and it degrades to equivalent
     * prompted JSON elsewhere), so this is a strictly safer choice, not a
     * narrow workaround.
     */
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'tone' => ['type' => 'string'],
            'examples' => ['type' => 'string'],
        ],
        'required' => ['tone', 'examples'],
        'additionalProperties' => false,
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a tone-of-voice analyst. You will be given a sample of real, on-brand written content. Analyze its tone, style, vocabulary, and cadence, then produce:
        1. A short, actionable tone-of-voice instruction (2-4 sentences), phrased as a direct instruction for another AI to follow when writing in this same voice.
        2. Two to three short illustrative example pairs in the exact format "Q: <a plausible question or topic>\nA: <an answer written strictly in the analyzed voice>", separated by a blank line between pairs.

        Respond with a single valid JSON object and nothing else:
        {"tone": "<the tone-of-voice instruction>", "examples": "<the Q/A pairs, joined with a blank line between each pair>"}
        Do not wrap the JSON in markdown or prose.
        PROMPT;

    public function __construct(
        private readonly Ai $ai,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Whether any enabled configuration currently supports text generation:
     * calibration dispatches a plain text-generation request, so this is the
     * same capability check Ai::text() would resolve against, just surfaced
     * up front so the UI can hide the form and explain why instead of
     * letting an editor paste a sample and hit a resolution failure.
     */
    public function isAvailable(): bool
    {
        try {
            $this->providerResolver->resolveForCapability(TextGenerationCapableInterface::class);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok: true, tone: string, examples: string}|array{ok: false, message: string}
     */
    public function calibrate(string $exampleText): array
    {
        $exampleText = trim($exampleText);
        $length = mb_strlen($exampleText);
        if ($length < self::MIN_LENGTH) {
            return ['ok' => false, 'message' => 'Paste a longer sample (at least a few sentences) for a reliable analysis.'];
        }
        if ($length > self::MAX_LENGTH) {
            return ['ok' => false, 'message' => sprintf('That sample is too long (%d characters, max %d). Shorten it and try again.', $length, self::MAX_LENGTH)];
        }

        $response = $this->ai->request()
            ->text()
            ->prompt($exampleText)
            ->systemPrompt(self::SYSTEM_PROMPT)
            ->disableSystemPromptComposition()
            ->responseFormat(ResponseFormat::jsonSchema('voice_calibration', self::RESPONSE_SCHEMA))
            ->maxTokens(700)
            ->temperature(0.3)
            ->from('aim')
            ->send();

        if (!$response->isSuccessful()) {
            return ['ok' => false, 'message' => $response->errors[0] ?? 'The AI call did not succeed.'];
        }

        $parsed = $this->parseResult($response->content);
        if ($parsed === null) {
            return ['ok' => false, 'message' => 'The AI response could not be parsed. Try again.'];
        }

        return ['ok' => true, 'tone' => $parsed['tone'], 'examples' => $parsed['examples']];
    }

    /**
     * @return array{tone: string, examples: string}|null
     */
    private function parseResult(string $raw): ?array
    {
        $json = JsonExtractor::extractJsonObject($raw);
        if ($json === null) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !isset($decoded['tone'])) {
            return null;
        }

        return [
            'tone' => trim((string)$decoded['tone']),
            'examples' => trim((string)($decoded['examples'] ?? '')),
        ];
    }
}
