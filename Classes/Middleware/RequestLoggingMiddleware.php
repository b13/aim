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
use B13\Aim\Governance\PrivacyLevel;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Logs every AI request/response to the tx_aim_request_log table.
 *
 * Captures timing, token usage (including cached/reasoning tokens),
 * cost, success/failure, and the full raw usage payload for future
 * analysis and smart routing decisions.
 *
 * Priority -700: runs after CostTrackingMiddleware (-800) on the way
 * back, so cost is already computed. Wraps EventDispatch and CoreDispatch.
 */
#[AsAiMiddleware(priority: -700)]
final class RequestLoggingMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly RequestLogRepository $repository,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $start = hrtime(true);
        $response = null;
        $error = null;

        try {
            $response = $next->handle($request, $provider, $configuration);
            return $response;
        } catch (\Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $durationMs = (int)((hrtime(true) - $start) / 1_000_000);
            $this->logRequest($request, $response, $configuration, $durationMs, $error, $next->context);
        }
    }

    private function logRequest(
        AiRequestInterface $request,
        ?TextResponse $response,
        ProviderConfiguration $configuration,
        int $durationMs,
        ?\Throwable $error,
        RequestContext $context,
    ): void {
        // Determine effective privacy level
        $privacyLevel = $this->resolvePrivacyLevel($configuration, $request);
        if ($privacyLevel === PrivacyLevel::None) {
            return;
        }
        $requestClass = (new \ReflectionClass($request))->getShortName();
        $metadata = $this->extractMetadata($request);
        $extensionKey = (string)($metadata['extension'] ?? '');
        $userId = $this->resolveUserId($request);

        [$prompt, $systemPrompt] = $this->extractPromptContent($request);

        // Detect auto model switch
        $isAutoSwitch = (bool)$configuration->get('_auto_model_switch', false);
        $originalModel = (string)$configuration->get('_auto_model_switch_from', '');
        $switchReason = (string)$configuration->get('_auto_model_switch_reason', '');

        // Detect application-level fallback (e.g. native provider failed, adapter took over).
        // Only mark as fallback when a different provider is handling the request than the
        // one that originally failed - avoids tagging the failed primary attempt itself.
        $isFallbackFromMetadata = isset($metadata['fallback_from'])
            && $metadata['fallback_from'] !== $configuration->providerIdentifier;

        $rerouted = $isAutoSwitch || $isFallbackFromMetadata;
        $rerouteType = $isAutoSwitch ? 'model_switch' : ($isFallbackFromMetadata ? 'fallback' : '');
        $rerouteReason = $switchReason ?: ($isFallbackFromMetadata ? ($metadata['fallback_reason'] ?? '') : '');

        $data = [
            'request_type' => $requestClass,
            'provider_identifier' => $configuration->providerIdentifier,
            'configuration_uid' => $configuration->uid,
            'model_requested' => $isAutoSwitch ? $originalModel : $configuration->model,
            'extension_key' => $extensionKey,
            'duration_ms' => $durationMs,
            'user_id' => $userId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'rerouted' => $rerouted ? 1 : 0,
            'reroute_type' => $rerouteType,
            'reroute_reason' => $rerouteReason,
            'request_prompt' => $prompt,
            'request_system_prompt' => $systemPrompt,
        ];

        if ($response !== null) {
            $usage = $response->usage;
            $data['success'] = $response->isSuccessful() ? 1 : 0;
            $data['prompt_tokens'] = $usage->promptTokens;
            $data['completion_tokens'] = $usage->completionTokens;
            $data['cached_tokens'] = $usage->cachedTokens;
            $data['reasoning_tokens'] = $usage->reasoningTokens;
            $data['total_tokens'] = $usage->getTotalTokens();
            $data['cost'] = $usage->cost;
            $data['model_used'] = $usage->modelUsed !== '' ? $usage->modelUsed : $configuration->model;
            $data['system_fingerprint'] = $usage->systemFingerprint;
            $data['raw_usage'] = $usage->rawUsage !== [] ? json_encode($usage->rawUsage, JSON_THROW_ON_ERROR) : '';
            $data['error_message'] = $response->errors[0] ?? '';
            $data['response_content'] = $response->content;
        } else {
            $data['success'] = 0;
            $data['model_used'] = $configuration->model;
            $data['error_message'] = $error?->getMessage() ?? 'Unknown error';
        }

        // Store complexity classification from SmartRoutingMiddleware
        if ($context->complexity !== null) {
            $data['complexity_score'] = (float)($context->complexity['score'] ?? 0);
            $data['complexity_label'] = (string)($context->complexity['label'] ?? '');
            $data['complexity_reason'] = (string)($context->complexity['reason'] ?? '');
        }

        // Store fallback info from RetryWithFallbackMiddleware
        if ($context->fallbackInfo !== null) {
            $data['rerouted'] = 1;
            $data['reroute_type'] = 'fallback';
            $data['reroute_reason'] = (string)($context->fallbackInfo['reason'] ?? '');
        }

        // Redact content for reduced privacy
        if ($privacyLevel === PrivacyLevel::Reduced) {
            $data['request_prompt'] = '';
            $data['request_system_prompt'] = '';
            $data['response_content'] = '';
            $data['metadata'] = '{}';
        }

        try {
            $this->repository->log($data);
        } catch (\Throwable $logError) {
            $this->logger->error('AiM request log insert failed: ' . $logError->getMessage(), [
                'data' => $data,
            ]);
        }
    }

    /**
     * Resolve the effective privacy level from provider config, user TSconfig,
     * and any per-request override carried on the request. The strictest of
     * the three wins — an override can only escalate, never relax.
     */
    private function resolvePrivacyLevel(ProviderConfiguration $configuration, AiRequestInterface $request): PrivacyLevel
    {
        $level = PrivacyLevel::fromString($configuration->privacyLevel);

        $user = $this->getBackendUser();
        if ($user !== null && method_exists($user, 'getTSConfig')) {
            $userLevel = PrivacyLevel::fromString(
                (string)($user->getTSConfig()['aim.']['privacyLevel'] ?? 'standard')
            );
            $level = $level->strictest($userLevel);
        }

        $requestOverride = $request->getPrivacyLevelOverride();
        if ($requestOverride !== null) {
            $level = $level->strictest($requestOverride);
        }

        return $level;
    }

    private function extractMetadata(AiRequestInterface $request): array
    {
        if (property_exists($request, 'metadata') && is_array($request->metadata)) {
            return $request->metadata;
        }
        return [];
    }

    /**
     * Extract the user prompt and system prompt from any request type.
     *
     * @return array{0: string, 1: string} [prompt, systemPrompt]
     */
    private function extractPromptContent(AiRequestInterface $request): array
    {
        $prompt = '';
        $systemPrompt = '';

        // Direct prompt property (TextGenerationRequest, VisionRequest, TranslationRequest)
        if (property_exists($request, 'prompt') && is_string($request->prompt)) {
            $prompt = $request->prompt;
        }
        // Translation: use the text being translated
        if (property_exists($request, 'text') && is_string($request->text)) {
            $prompt = $request->text;
        }
        // Conversation/ToolCalling: extract from messages array
        if ($prompt === '' && property_exists($request, 'messages') && is_array($request->messages)) {
            $userMessages = [];
            foreach ($request->messages as $msg) {
                if (is_object($msg) && property_exists($msg, 'role') && $msg->role === 'user') {
                    $content = is_string($msg->content) ? $msg->content : '';
                    if ($content !== '') {
                        $userMessages[] = $content;
                    }
                }
            }
            $prompt = implode("\n", $userMessages);
        }
        // Embedding: join input texts
        if ($prompt === '' && property_exists($request, 'input') && is_array($request->input)) {
            $prompt = implode("\n", array_filter($request->input, 'is_string'));
        }

        if (property_exists($request, 'systemPrompt') && is_string($request->systemPrompt)) {
            $systemPrompt = $request->systemPrompt;
        }

        return [$prompt, $systemPrompt];
    }

    private function resolveUserId(AiRequestInterface $request): int
    {
        // Try request user property first
        if (property_exists($request, 'user') && is_string($request->user) && $request->user !== '') {
            $userId = (int)$request->user;
            if ($userId > 0) {
                return $userId;
            }
        }
        // Fall back to current backend user
        return (int)(($this->getBackendUser())?->user['uid'] ?? 0);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
