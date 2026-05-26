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

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Grading\GradeLabel;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\ResponseFormat;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Runs LLM-as-a-judge grading for a previously logged request.
 *
 * Reads a row from tx_aim_request_log, calls the configured judge provider
 * directly (bypassing the middleware pipeline to avoid a DI cycle and to keep
 * the judge call out of the request log), parses a JSON score/label/reason
 * out of the response, and writes the result back onto the original row.
 *
 * Called by GraderMiddleware (via register_shutdown_function) for the live path,
 * and by GradePendingLogsCommand for the scheduler safety-net. The judge's own
 * cost is tracked on the graded row (judge_cost column) and rolled into the
 * judge configuration's total_cost via the repository.
 */
#[Autoconfigure(public: true)]
class GradingService
{
    private const JSON_INSTRUCTION = "\n\nRespond with a single valid JSON object and nothing else:\n"
        . '{"score": <float between 0.0 and 1.0>, "label": "<poor|fair|good|excellent>", "reason": "<one short sentence>"}'
        . "\nDo not wrap the JSON in markdown or prose.";

    public function __construct(
        private readonly RequestLogRepository $logRepository,
        private readonly ProviderConfigurationRepository $configurationRepository,
        private readonly ProviderResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Grade the request log row identified by $logUid.
     *
     * Idempotent in spirit: callers should ensure they only invoke this for
     * rows in grade_status='pending'. The method itself does not double-check —
     * the shutdown handler holds the freshly inserted uid and the scheduler
     * filters by status, so a re-entry would only happen on a true race.
     */
    public function grade(int $logUid): void
    {
        if ($logUid <= 0) {
            return;
        }

        $start = hrtime(true);
        try {
            $row = $this->logRepository->findByUid($logUid);
            if ($row === null) {
                return;
            }

            $primaryConfig = $this->configurationRepository->findByUid((int)($row['configuration_uid'] ?? 0));
            if ($primaryConfig === null || !$primaryConfig->gradingEnabled) {
                // Config gone or grading turned off between insert and grade — nothing to do.
                return;
            }

            $judgeUid = $primaryConfig->judgeConfigurationUid;
            if ($judgeUid <= 0 || $judgeUid === $primaryConfig->uid) {
                $this->logRepository->markGradeFailed(
                    $logUid,
                    'Judge configuration is missing or points at the same configuration.',
                );
                return;
            }

            $prompt = (string)($row['request_prompt'] ?? '');
            $response = (string)($row['response_content'] ?? '');
            if ($prompt === '' || $response === '') {
                $this->logRepository->markGradeFailed(
                    $logUid,
                    'Prompt or response content is empty (likely redacted) — cannot grade.',
                );
                return;
            }

            $judgeResolved = $this->resolver->resolveForCapability(ConversationCapableInterface::class, $judgeUid);
            $judgeProvider = $judgeResolved->manifest->getInstance();
            if (!$judgeProvider instanceof ConversationCapableInterface) {
                $this->logRepository->markGradeFailed(
                    $logUid,
                    'Judge provider does not support conversation capability.',
                );
                return;
            }

            $judgeRequest = $this->buildJudgeRequest($primaryConfig, $judgeResolved->configuration, $prompt, $response, $logUid);
            $judgeResponse = $judgeProvider->processConversationRequest($judgeRequest);

            // The judge call bypasses the middleware pipeline (would otherwise create
            // a DI cycle and a duplicate request-log row), so CostTrackingMiddleware
            // never sees it. Roll the spend into the judge configuration's total_cost
            // here instead. This is done as soon as the call returns so a paid-for but
            // unparseable response is still accounted for.
            $this->configurationRepository->updateTotalCost($judgeUid, $judgeResponse->usage->cost);

            if (!$judgeResponse->isSuccessful()) {
                $error = $judgeResponse->errors[0] ?? 'Judge returned an unsuccessful response.';
                $this->logRepository->markGradeFailed($logUid, $error);
                return;
            }

            $parsed = $this->parseJudgeOutput($judgeResponse->content);
            if ($parsed === null) {
                $this->logRepository->markGradeFailed(
                    $logUid,
                    'Judge response could not be parsed as JSON: ' . mb_substr($judgeResponse->content, 0, 200),
                );
                return;
            }

            $durationMs = (int)((hrtime(true) - $start) / 1_000_000);
            $this->logRepository->updateGrade(
                $logUid,
                $parsed['score'],
                $parsed['label'],
                $parsed['reason'],
                $judgeResponse->usage->modelUsed ?: '',
                $judgeResponse->usage->cost,
                $durationMs,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('AiM grading failed for log uid ' . $logUid . ': ' . $e->getMessage());
            $this->logRepository->markGradeFailed($logUid, $e->getMessage());
        }
    }

    private function buildJudgeRequest(
        ProviderConfiguration $primaryConfig,
        ProviderConfiguration $judgeConfig,
        string $prompt,
        string $response,
        int $logUid,
    ): ConversationRequest {
        $rubric = trim($primaryConfig->gradingRubric);
        if ($rubric === '') {
            $rubric = 'Evaluate the response for factual accuracy and relevance to the user prompt.';
        }
        $systemPrompt = $rubric . self::JSON_INSTRUCTION;

        $userContent = "Prompt:\n" . $prompt . "\n\nResponse:\n" . $response;

        return new ConversationRequest(
            configuration: $judgeConfig,
            messages: [new UserMessage($userContent)],
            systemPrompt: $systemPrompt,
            responseFormat: ResponseFormat::json(),
            maxTokens: 300,
            temperature: 0.0,
            metadata: [
                '_aim_grading' => true,
                'graded_log_uid' => $logUid,
                'extension' => 'aim',
            ],
        );
    }

    /**
     * Parse and validate the judge's JSON output.
     *
     * @return array{score: float, label: GradeLabel, reason: string}|null
     */
    private function parseJudgeOutput(string $raw): ?array
    {
        $json = $this->extractJsonObject($raw);
        if ($json === null) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !isset($decoded['score'], $decoded['label'])) {
            return null;
        }

        $score = (float)$decoded['score'];
        if ($score < 0.0) {
            $score = 0.0;
        }
        if ($score > 1.0) {
            $score = 1.0;
        }

        $label = GradeLabel::tryFrom(strtolower(trim((string)$decoded['label'])))
            ?? GradeLabel::fromScore($score);

        $reason = trim((string)($decoded['reason'] ?? ''));
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 497) . '...';
        }

        return ['score' => $score, 'label' => $label, 'reason' => $reason];
    }

    /**
     * Strip code fences and locate the first balanced JSON object in the string.
     * Tolerates the common failure mode of LLMs wrapping JSON in markdown.
     */
    private function extractJsonObject(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Strip ```json ... ``` fence if present
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*\n?|\n?```$/m', '', $raw) ?? $raw;
            $raw = trim($raw);
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return substr($raw, $start, $end - $start + 1);
    }
}
