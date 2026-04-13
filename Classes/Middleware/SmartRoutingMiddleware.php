<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Middleware;

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use B13\Aim\Routing\ComplexitySignalRegistry;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Classifies prompt complexity and optionally reroutes to a cheaper model.
 *
 * Runs between RetryWithFallback (100) and CapabilityValidation (50).
 * Uses lightweight heuristics (no external API calls) to score complexity,
 * then checks historical performance data to decide if a cheaper model
 * can handle the request.
 *
 * The routing decision is logged in the request_log (complexity_score,
 * complexity_label, complexity_reason) for ongoing analysis.
 *
 * Priority 75: after retry (which catches errors) but before capability
 * validation (which may reroute based on capability mismatches).
 */
#[AsAiMiddleware(priority: 75)]
final class SmartRoutingMiddleware implements AiMiddlewareInterface
{
    /**
     * Minimum number of historical requests for a model before
     * we trust its performance data for routing decisions.
     */
    private const MIN_HISTORY_REQUESTS = 10;

    /**
     * Minimum success rate (%) for a model to be considered as a
     * cheaper alternative.
     */
    private const MIN_SUCCESS_RATE = 90.0;

    public function __construct(
        private readonly RequestLogRepository $logRepository,
        private readonly ProviderResolver $providerResolver,
        private readonly ComplexitySignalRegistry $signalRegistry,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        // Skip routing for embedding requests — model selection matters there
        if ($request instanceof EmbeddingRequest) {
            return $next->handle($request, $provider, $configuration);
        }

        $prompt = $this->extractPrompt($request);
        if ($prompt === '') {
            return $next->handle($request, $provider, $configuration);
        }

        // Classify complexity
        $classification = $this->classifyComplexity($prompt);

        // Store on the per-request context (read by RequestLoggingMiddleware)
        $next->context->complexity = $classification;

        // Respect rerouting_allowed flag — never reroute away from protected configs
        if (!$configuration->reroutingAllowed) {
            return $next->handle($request, $provider, $configuration);
        }

        // For simple prompts, check if a cheaper model can handle it
        if ($classification['label'] === 'simple') {
            $cheaperResult = $this->findCheaperModel($request, $configuration);
            if ($cheaperResult !== null) {
                $this->logger->info(sprintf(
                    'Smart routing: downgrading from "%s" to cheaper model "%s" for simple prompt (score: %.2f, reason: %s)',
                    $configuration->model,
                    $cheaperResult['configuration']->model,
                    $classification['score'],
                    $classification['reason'],
                ));

                return $next->handle(
                    $request,
                    $cheaperResult['provider'],
                    $cheaperResult['configuration'],
                );
            }
        }

        return $next->handle($request, $provider, $configuration);
    }

    /**
     * Classify prompt complexity using language-agnostic structural heuristics.
     *
     * No hardcoded keywords in any language — only structural signals:
     * length, sentence count, punctuation patterns, code presence,
     * list/enumeration patterns, and structural complexity.
     *
     * @return array{score: float, label: string, reason: string}
     */
    private function classifyComplexity(string $prompt): array
    {
        $prompt = trim($prompt);
        $charCount = mb_strlen($prompt);
        $score = 0.0;
        $reasons = [];

        // --- Length signals (language-agnostic) ---
        if ($charCount <= 20) {
            $score -= 0.3;
            $reasons[] = 'very short (' . $charCount . ' chars)';
        } elseif ($charCount <= 80) {
            $score -= 0.1;
            $reasons[] = 'short (' . $charCount . ' chars)';
        } elseif ($charCount > 500) {
            $score += 0.3;
            $reasons[] = 'long (' . $charCount . ' chars)';
        } elseif ($charCount > 200) {
            $score += 0.15;
            $reasons[] = 'medium (' . $charCount . ' chars)';
        }

        // --- Sentence structure (works for all Latin/CJK punctuation) ---
        $sentenceEnders = preg_match_all('/[.!?;。！？；]+/u', $prompt);
        if ($sentenceEnders >= 4) {
            $score += 0.2;
            $reasons[] = 'multi-sentence (' . $sentenceEnders . ')';
        } elseif ($sentenceEnders >= 2) {
            $score += 0.1;
            $reasons[] = $sentenceEnders . ' sentences';
        }

        // --- Multiple questions ---
        $questionCount = preg_match_all('/[?？]+/u', $prompt);
        if ($questionCount > 2) {
            $score += 0.2;
            $reasons[] = $questionCount . ' questions';
        } elseif ($questionCount > 1) {
            $score += 0.1;
            $reasons[] = $questionCount . ' questions';
        }

        // --- Enumeration / lists (numbered or bulleted) ---
        $listItems = preg_match_all('/^\s*(?:\d+[.)]\s|[-*•]\s)/um', $prompt);
        if ($listItems >= 3) {
            $score += 0.25;
            $reasons[] = 'enumeration (' . $listItems . ' items)';
        } elseif ($listItems >= 1) {
            $score += 0.1;
            $reasons[] = 'list items';
        }

        // --- Code presence (universal syntax patterns) ---
        if (preg_match('/```|{[\s\n]|=>|->|\$\w+|function\s*\(|class\s+\w|import\s|def\s|SELECT\s|CREATE\s/i', $prompt)) {
            $score += 0.25;
            $reasons[] = 'contains code';
        }

        // --- Line breaks / paragraphs (structured multi-part input) ---
        $lineCount = substr_count($prompt, "\n") + 1;
        if ($lineCount >= 5) {
            $score += 0.15;
            $reasons[] = 'multi-line (' . $lineCount . ' lines)';
        }

        // --- URLs (reference material = more context needed) ---
        if (preg_match('/https?:\/\//', $prompt)) {
            $score += 0.1;
            $reasons[] = 'contains URL';
        }

        // --- Structural delimiters (parentheses, brackets = structured thinking) ---
        $delimiterCount = preg_match_all('/[(\[{<>}\])]/', $prompt);
        if ($delimiterCount >= 6) {
            $score += 0.1;
            $reasons[] = 'structured delimiters';
        }

        // --- Language-specific keyword signals (loaded from extensions) ---
        $signals = $this->signalRegistry->getSignals();
        $promptLower = mb_strtolower($prompt);

        foreach ($signals['complex'] as $keyword) {
            if (mb_strpos($promptLower, $keyword) !== false) {
                $score += 0.25;
                $reasons[] = 'keyword: "' . $keyword . '"';
                break;
            }
        }
        foreach ($signals['simple'] as $keyword) {
            if (str_starts_with($promptLower, $keyword)) {
                $score -= 0.25;
                $reasons[] = 'simple: "' . $keyword . '"';
                break;
            }
        }
        foreach ($signals['multiPart'] as $signal) {
            if (mb_strpos($promptLower, $signal) !== false) {
                $score += 0.15;
                $reasons[] = 'multi-part: "' . trim($signal) . '"';
                break;
            }
        }

        // --- Clamp to 0-1 ---
        $score = max(0.0, min(1.0, $score));

        if ($score < 0.3) {
            $label = 'simple';
        } elseif ($score < 0.6) {
            $label = 'moderate';
        } else {
            $label = 'complex';
        }

        return [
            'score' => round($score, 4),
            'label' => $label,
            'reason' => implode('; ', $reasons) ?: 'default',
        ];
    }

    /**
     * Find a cheaper model that can reliably handle simple requests.
     *
     * Queries historical performance data from the request log to find
     * models with lower cost but high success rates for the same request type.
     *
     * @return array{provider: AiProviderInterface, configuration: ProviderConfiguration}|null
     */
    private function findCheaperModel(AiRequestInterface $request, ProviderConfiguration $currentConfig): ?array
    {
        $requestType = (new \ReflectionClass($request))->getShortName();

        try {
            $profiles = $this->logRepository->getModelPerformanceProfile($requestType);
        } catch (\Throwable) {
            return null;
        }

        if ($profiles === []) {
            return null;
        }

        // Find current model's average cost
        $currentCost = null;
        foreach ($profiles as $profile) {
            if ($profile['model_used'] === $currentConfig->model) {
                $currentCost = $profile['avg_cost'];
                break;
            }
        }

        if ($currentCost === null || $currentCost <= 0) {
            return null;
        }

        // Find a cheaper model with good success rate
        $bestCandidate = null;
        foreach ($profiles as $profile) {
            if ($profile['model_used'] === $currentConfig->model) {
                continue;
            }
            if ($profile['request_count'] < self::MIN_HISTORY_REQUESTS) {
                continue;
            }
            if ($profile['success_rate'] < self::MIN_SUCCESS_RATE) {
                continue;
            }
            if ($profile['avg_cost'] >= $currentCost) {
                continue;
            }

            // Cheaper + reliable — is there a config for this model?
            if ($bestCandidate === null || $profile['avg_cost'] < $bestCandidate['avg_cost']) {
                $bestCandidate = $profile;
            }
        }

        if ($bestCandidate === null) {
            return null;
        }

        // Resolve the cheaper model's provider + config
        try {
            $capabilityFqcn = $this->resolveCapabilityForRequest($request);
            if ($capabilityFqcn === null) {
                return null;
            }
            $allProviders = $this->providerResolver->resolveAllForCapability($capabilityFqcn);
            foreach ($allProviders as $resolved) {
                if ($resolved->configuration->model === $bestCandidate['model_used']
                    && !$resolved->configuration->disabled
                    && $resolved->configuration->reroutingAllowed
                    && $this->isAccessibleByCurrentUser($resolved->configuration)
                ) {
                    return [
                        'provider' => $resolved->manifest->getInstance(),
                        'configuration' => $resolved->configuration,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Check if the current backend user can access a provider configuration.
     */
    private function isAccessibleByCurrentUser(ProviderConfiguration $config): bool
    {
        if ($config->beGroups === '') {
            return true;
        }
        $user = $this->getBackendUser();
        if ($user === null || $user->isAdmin()) {
            return true;
        }
        $allowedGroupIds = array_map('intval', explode(',', $config->beGroups));
        $userGroupIds = array_map('intval', $user->userGroupsUID ?? []);
        return array_intersect($allowedGroupIds, $userGroupIds) !== [];
    }

    private function extractPrompt(AiRequestInterface $request): string
    {
        if (property_exists($request, 'prompt') && is_string($request->prompt)) {
            return $request->prompt;
        }
        if (property_exists($request, 'text') && is_string($request->text)) {
            return $request->text;
        }
        if (property_exists($request, 'messages') && is_array($request->messages)) {
            foreach (array_reverse($request->messages) as $msg) {
                if (is_object($msg) && property_exists($msg, 'role') && $msg->role === 'user') {
                    $content = is_string($msg->content) ? $msg->content : '';
                    if ($content !== '') {
                        return $content;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Map a request instance to the capability interface it requires.
     *
     * @return class-string|null
     */
    private function resolveCapabilityForRequest(AiRequestInterface $request): ?string
    {
        return match (true) {
            $request instanceof VisionRequest => VisionCapableInterface::class,
            $request instanceof ConversationRequest => ConversationCapableInterface::class,
            $request instanceof TextGenerationRequest => TextGenerationCapableInterface::class,
            $request instanceof TranslationRequest => TranslationCapableInterface::class,
            $request instanceof ToolCallingRequest => ToolCallingCapableInterface::class,
            default => null,
        };
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
