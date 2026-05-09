<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Provider\SymfonyAi;

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\Message\AbstractMessage;
use B13\Aim\Request\Message\AssistantMessage as AimAssistantMessage;
use B13\Aim\Request\Message\ToolMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\EmbeddingResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use B13\Aim\Response\ToolCall;
use B13\Aim\Response\ToolCallingResponse;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Bridges any Symfony AI Platform bridge to AiM's provider system.
 *
 * This adapter wraps a Symfony AI bridge (OpenAI, Anthropic, Gemini, Mistral,
 * etc.) as a native AiM provider. All requests flow through AiM's full
 * middleware pipeline: logging, cost tracking, capability validation, fallback.
 * The adapter only handles request/response mapping.
 *
 * Install a Symfony AI bridge package (e.g. symfony/ai-open-ai-platform),
 * then configure a provider in AiM's backend module. Available bridges are
 * auto-discovered at container compile time.
 */
class SymfonyAiPlatformAdapter implements
    AiProviderInterface,
    VisionCapableInterface,
    ConversationCapableInterface,
    TextGenerationCapableInterface,
    TranslationCapableInterface,
    ToolCallingCapableInterface,
    EmbeddingCapableInterface
{
    /** @var array<string, PlatformInterface> Platforms cached by configuration key */
    private array $platforms = [];

    private readonly string $maxTokensKey;

    /**
     * @param string $factoryClass Fully-qualified class name of the bridge's PlatformFactory
     * @param string $factoryParam Name of the factory parameter to pass the config value to ('apiKey' or 'endpoint')
     */
    public function __construct(
        private readonly string $factoryClass,
        private readonly string $factoryParam = 'apiKey',
    ) {
        $this->maxTokensKey = self::resolveMaxTokensKey($factoryClass);
    }

    public function processVisionRequest(VisionRequest $request): TextResponse
    {
        $platform = $this->getPlatform($request->configuration);
        $messages = new MessageBag(
            Message::forSystem($request->systemPrompt ?: 'You are a helpful AI assistant that analyzes images.'),
            Message::ofUser(
                $request->prompt,
                Image::fromDataUrl('data:' . $request->mimeType . ';base64,' . $request->imageData),
            ),
        );

        try {
            $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature);
            $result = $platform->invoke($request->configuration->model, $messages, $options);
            return $this->toTextResponse($result, $request->configuration);
        } catch (\Throwable $e) {
            return new TextResponse('', errors: ['Symfony AI error: ' . $e->getMessage()]);
        }
    }

    public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
    {
        $platform = $this->getPlatform($request->configuration);
        $messages = new MessageBag(
            Message::forSystem($request->systemPrompt ?: 'You are a helpful AI assistant.'),
            Message::ofUser($request->prompt),
        );

        $extra = [];
        if ($request->responseFormat !== null) {
            $extra['response_format'] = $request->responseFormat->toArray();
        }
        $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature, $extra);

        try {
            $result = $platform->invoke($request->configuration->model, $messages, $options);
            return $this->toTextResponse($result, $request->configuration);
        } catch (\Throwable $e) {
            return new TextResponse('', errors: ['Symfony AI error: ' . $e->getMessage()]);
        }
    }

    public function processTranslationRequest(TranslationRequest $request): TextResponse
    {
        $platform = $this->getPlatform($request->configuration);
        $systemPrompt = $request->systemPrompt
            ?: 'You are an AI assistant that accurately translates text while preserving the original meaning, tone, and context. Adapt cultural references where appropriate and ensure the result sounds natural and fluent in the target language. Output ONLY the translated text. No explanations, no alternatives, no commentary.';
        $userPrompt = sprintf(
            "Translate the following text from %s to %s. Maintain the original tone, context, and meaning.\n\nText: \"%s\"",
            $request->sourceLanguage,
            $request->targetLanguage,
            $request->text,
        );
        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userPrompt),
        );

        try {
            $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature);
            $result = $platform->invoke($request->configuration->model, $messages, $options);
            return $this->toTextResponse($result, $request->configuration);
        } catch (\Throwable $e) {
            return new TextResponse('', errors: ['Symfony AI error: ' . $e->getMessage()]);
        }
    }

    public function processConversationRequest(ConversationRequest $request): ConversationResponse
    {
        $stream = $request->stream ?? false;
        $platform = $this->getPlatform($request->configuration);
        $messages = $this->buildMessageBag($request->messages, $request->systemPrompt);

        $extra = [];
        if ($request->responseFormat !== null) {
            $extra['response_format'] = $request->responseFormat->toArray();
        }
        if ($stream) {
            $extra['stream'] = true;
        }
        $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature, $extra);

        $start = hrtime(true);
        try {
            $result = $platform->invoke($request->configuration->model, $messages, $options);

            if ($stream) {
                $streamIterator = new StreamChunkIterator(
                    $result->asStream(),
                    $request->configuration,
                    onComplete: function (AiUsageStatistics $usage, string $fullContent) use ($request, $start): void {
                        $this->logStreamingRequest($request, $usage, $fullContent, $start);
                    },
                );
                return new ConversationResponse('', streamIterator: $streamIterator);
            }

            $textResponse = $this->toTextResponse($result, $request->configuration);
            return new ConversationResponse(
                $textResponse->content,
                $textResponse->usage,
                $textResponse->rawResponse,
                $textResponse->errors,
            );
        } catch (\Throwable $e) {
            return new ConversationResponse('', errors: ['Symfony AI error: ' . $e->getMessage()]);
        }
    }

    public function processToolCallingRequest(ToolCallingRequest $request): ToolCallingResponse
    {
        $platform = $this->getPlatform($request->configuration);
        $messages = $this->buildMessageBag($request->messages, $request->systemPrompt);

        // Convert ToolDefinitions to the format expected by the target provider.
        // Anthropic uses {name, description, input_schema}.
        // OpenAI / Mistral / Gemini / Ollama use the OpenAI function-calling
        // schema {type: function, function: {name, description, parameters}}.
        $tools = $request->configuration->providerIdentifier === 'anthropic'
            ? array_map(static fn($tool) => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->parameters ?: ['type' => 'object'],
            ], $request->tools)
            : array_map(static fn($tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters ?: ['type' => 'object'],
                ],
            ], $request->tools);

        $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature, [
            'tools' => $tools,
        ]);

        $start = hrtime(true);
        try {
            $result = $platform->invoke($request->configuration->model, $messages, $options);
            $usage = $this->extractUsage($result, $request->configuration);
            $rawResponse = $this->extractRawResponse($result);
            $content = $this->resolveTextContent($result);
            $toolCalls = $this->extractToolCallsFromRawResponse($rawResponse);

            $this->logToolCallingRequest($request, $usage, $content, $toolCalls, $start, null);
            return new ToolCallingResponse($content, $toolCalls, $usage, $rawResponse);
        } catch (\Throwable $e) {
            $this->logToolCallingRequest($request, new AiUsageStatistics(), '', [], $start, $e);
            return new ToolCallingResponse('', [], errors: ['Symfony AI error: ' . $e->getMessage()]);
        }
    }

    public function processEmbeddingRequest(EmbeddingRequest $request): EmbeddingResponse
    {
        $platform = $this->getPlatform($request->configuration);
        $model = $request->model !== '' ? $request->model : $request->configuration->model;

        $options = [];
        if ($request->dimensions > 0) {
            $options['dimensions'] = $request->dimensions;
        }

        try {
            $result = $platform->invoke($model, $request->input, $options);
            $usage = $this->extractUsage($result, $request->configuration);
            $rawResponse = $this->extractRawResponse($result);

            $embeddings = [];
            foreach ($result->asVectors() as $vector) {
                if (is_object($vector) && method_exists($vector, 'getData')) {
                    $embeddings[] = $vector->getData();
                } elseif (is_array($vector)) {
                    $embeddings[] = $vector;
                }
            }

            return new EmbeddingResponse($embeddings, $usage, $rawResponse);
        } catch (\Throwable $e) {
            return new EmbeddingResponse(errors: ['Symfony AI error: ' . $e->getMessage()]);
        }
    }

    /**
     * Lazily create and cache a Platform instance per provider configuration.
     */
    private function getPlatform(ProviderConfiguration $config): PlatformInterface
    {
        $cacheKey = $config->uid > 0 ? (string)$config->uid : md5($config->apiKey . $config->model);
        if (!isset($this->platforms[$cacheKey])) {
            $factoryClass = $this->factoryClass;
            $this->platforms[$cacheKey] = match ($this->factoryParam) {
                'endpoint' => $factoryClass::create(endpoint: $config->apiKey),
                default => $factoryClass::create(apiKey: $config->apiKey),
            };
        }
        return $this->platforms[$cacheKey];
    }

    private function toTextResponse(object $result, ProviderConfiguration $config): TextResponse
    {
        $content = $this->resolveTextContent($result);
        $usage = $this->extractUsage($result, $config);
        $rawResponse = $this->extractRawResponse($result);

        if ($content === '') {
            return new TextResponse('', $usage, $rawResponse, errors: ['Provider returned an empty response.']);
        }

        return new TextResponse($content, $usage, $rawResponse);
    }

    private function resolveTextContent(object $result): string
    {
        try {
            return trim($result->asText(), '"\'');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Extract token usage from Symfony AI's metadata and map to AiM's AiUsageStatistics.
     *
     * Maps:
     *   TokenUsage::getPromptTokens()     -> promptTokens
     *   TokenUsage::getCompletionTokens() -> completionTokens
     *   TokenUsage::getThinkingTokens()   -> reasoningTokens
     *   TokenUsage::getCachedTokens()     -> cachedTokens
     *   Raw API response 'model'          -> modelUsed
     *   Raw API response 'usage'          -> rawUsage
     */
    private function extractUsage(object $result, ProviderConfiguration $config): AiUsageStatistics
    {
        $resolved = method_exists($result, 'getResult') ? $result->getResult() : $result;
        $tokenUsage = $resolved->getMetadata()->get('token_usage');
        if (!$tokenUsage instanceof TokenUsageInterface) {
            // Fallback: extract from raw API response (e.g. OpenAI embeddings
            // where Symfony AI's ResultConverter has no TokenUsageExtractor)
            return $this->extractUsageFromRawResponse($result, $config);
        }

        $promptTokens = $tokenUsage->getPromptTokens() ?? 0;
        $completionTokens = $tokenUsage->getCompletionTokens() ?? 0;
        $cachedTokens = $tokenUsage->getCachedTokens() ?? 0;
        $reasoningTokens = $tokenUsage->getThinkingTokens() ?? 0;

        $inputCost = (float)$config->get('input_token_cost', 0);
        $outputCost = (float)$config->get('output_token_cost', 0);
        $cost = (($promptTokens / 1_000_000) * $inputCost)
            + (($completionTokens / 1_000_000) * $outputCost);

        $rawResponse = $this->extractRawResponse($result);

        return new AiUsageStatistics(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            cost: $cost,
            cachedTokens: $cachedTokens,
            reasoningTokens: $reasoningTokens,
            modelUsed: (string)($rawResponse['model'] ?? ''),
            systemFingerprint: (string)($rawResponse['system_fingerprint'] ?? ''),
            rawUsage: $rawResponse['usage'] ?? [],
        );
    }

    private function extractUsageFromRawResponse(object $result, ProviderConfiguration $config): AiUsageStatistics
    {
        $rawResponse = $this->extractRawResponse($result);
        $usage = $rawResponse['usage'] ?? [];
        $promptTokens = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $inputCost = (float)$config->get('input_token_cost', 0);
        $outputCost = (float)$config->get('output_token_cost', 0);

        return new AiUsageStatistics(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            cost: (($promptTokens / 1_000_000) * $inputCost) + (($completionTokens / 1_000_000) * $outputCost),
            modelUsed: (string)($rawResponse['model'] ?? ''),
            rawUsage: $usage,
        );
    }

    private function extractRawResponse(object $result): array
    {
        try {
            $rawResult = $result->getRawResult();
            if ($rawResult !== null && method_exists($rawResult, 'getData')) {
                return $rawResult->getData();
            }
        } catch (\Throwable) {
        }
        return [];
    }

    /**
     * Extract tool calls from the raw API response.
     *
     * Handles multiple API formats:
     * - OpenAI Responses API (output[].function_call)
     * - OpenAI Chat Completions API (choices[].message.tool_calls)
     * - Anthropic (content[].tool_use)
     *
     * @return list<ToolCall>
     */
    private function extractToolCallsFromRawResponse(array $rawResponse): array
    {
        $toolCalls = [];

        // OpenAI Responses API format
        foreach ($rawResponse['output'] ?? [] as $output) {
            if (($output['type'] ?? '') === 'function_call') {
                $toolCalls[] = new ToolCall(
                    $output['call_id'] ?? $output['id'] ?? '',
                    $output['name'] ?? '',
                    $output['arguments'] ?? '{}',
                );
            }
        }
        if ($toolCalls !== []) {
            return $toolCalls;
        }

        // OpenAI Chat Completions API format
        foreach ($rawResponse['choices'] ?? [] as $choice) {
            foreach ($choice['message']['tool_calls'] ?? [] as $call) {
                $toolCalls[] = new ToolCall(
                    $call['id'] ?? '',
                    $call['function']['name'] ?? '',
                    $call['function']['arguments'] ?? '{}',
                );
            }
        }
        if ($toolCalls !== []) {
            return $toolCalls;
        }

        // Anthropic format
        foreach ($rawResponse['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    $block['id'] ?? '',
                    $block['name'] ?? '',
                    json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR),
                );
            }
        }

        return $toolCalls;
    }

    /**
     * Convert AiM messages to a Symfony AI MessageBag.
     *
     * @param list<AbstractMessage> $aiMessages
     */
    private function buildMessageBag(array $aiMessages, string $systemPrompt): MessageBag
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = Message::forSystem($systemPrompt);
        }
        foreach ($aiMessages as $msg) {
            $content = is_string($msg->content) ? $msg->content : '';
            // Assistant messages with tool calls must carry the calls into the
            // Symfony AI message so the wire format includes them. Otherwise
            // OpenAI-style providers (Mistral, OpenAI) reject the next round
            // with "Assistant message must have either content or tool_calls".
            if ($msg instanceof AimAssistantMessage && $msg->toolCalls !== []) {
                $symfonyToolCalls = array_map(
                    static fn(ToolCall $tc): SymfonyToolCall => new SymfonyToolCall(
                        $tc->id,
                        $tc->name,
                        $tc->getDecodedArguments(),
                    ),
                    $msg->toolCalls,
                );
                $messages[] = Message::ofAssistant($content !== '' ? $content : null, $symfonyToolCalls);
                continue;
            }
            // Tool result messages need the dedicated ToolCallMessage so the
            // wire format uses role=tool with tool_call_id (OpenAI/Mistral)
            // or maps to Anthropic's tool_result content blocks.
            if ($msg instanceof ToolMessage) {
                $messages[] = Message::ofToolCall(
                    new SymfonyToolCall($msg->toolCallId, '', []),
                    $content,
                );
                continue;
            }
            $messages[] = match ($msg->role) {
                'system' => Message::forSystem($content),
                'assistant' => Message::ofAssistant($content),
                default => Message::ofUser($content),
            };
        }
        return new MessageBag(...$messages);
    }

    /**
     * Build the options array for platform->invoke(), omitting temperature
     * for models that don't support it.
     *
     * @todo This uses a hardcoded list of model prefixes which is OpenAI-specific.
     *       A provider-agnostic solution (e.g. model catalog metadata or automatic
     *       retry on rejection) should replace this in a future version.
     */
    private function buildOptions(string $model, int $maxTokens, float $temperature, array $extra = []): array
    {
        $options = [$this->maxTokensKey => $maxTokens] + $extra;
        if (!$this->isReasoningModel($model)) {
            $options['temperature'] = $temperature;
        }
        return $options;
    }

    /**
     * Resolve the max-tokens option key expected by a Symfony AI bridge based on the used bridge
     */
    public static function resolveMaxTokensKey(string $factoryClass): string
    {
        if (str_contains($factoryClass, '\\Bridge\\OpenAi\\')
            || str_contains($factoryClass, '\\Bridge\\OpenResponses\\')
        ) {
            return 'max_output_tokens';
        }
        return 'max_tokens';
    }

    /**
     * Check if a model is a reasoning model that doesn't support temperature.
     *
     * @todo Replace with provider-agnostic detection once model catalogs expose this.
     */
    private function isReasoningModel(string $model): bool
    {
        foreach (['o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini'] as $prefix) {
            if ($model === $prefix || str_starts_with($model, $prefix . '-')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Persist a request log entry after a streaming conversation completes.
     *
     * Streaming bypasses the synchronous middleware pipeline (see Ai::conversationStream),
     * so RequestLoggingMiddleware never sees the response. This callback fills the gap.
     * Tracks: https://github.com/b13/aim/issues/7
     */
    private function logStreamingRequest(
        ConversationRequest $request,
        AiUsageStatistics $usage,
        string $fullContent,
        float $start,
    ): void {
        $userMessages = [];
        foreach ($request->messages as $msg) {
            if (is_object($msg) && property_exists($msg, 'role') && $msg->role === 'user'
                && property_exists($msg, 'content') && is_string($msg->content) && $msg->content !== '') {
                $userMessages[] = $msg->content;
            }
        }

        $this->writeRequestLog($request->configuration, [
            'request_type' => 'ConversationRequest',
            'usage' => $usage,
            'metadata' => is_array($request->metadata ?? null) ? $request->metadata : [],
            'duration_ms' => (int)((hrtime(true) - $start) / 1_000_000),
            'success' => 1,
            'error_message' => '',
            'request_prompt' => implode("\n", $userMessages),
            'request_system_prompt' => $request->systemPrompt,
            'response_content' => $fullContent,
        ]);
    }

    /**
     * Persist a request log entry for a tool-calling request.
     *
     * Callers like Dkd\LlmChat\Agent\AgentDispatcher invoke processToolCallingRequest()
     * directly via getCapability() and bypass the middleware pipeline, so logging
     * has to happen here. Tracks: https://github.com/b13/aim/issues/7
     *
     * @param list<ToolCall> $toolCalls
     */
    private function logToolCallingRequest(
        ToolCallingRequest $request,
        AiUsageStatistics $usage,
        string $content,
        array $toolCalls,
        float $start,
        ?\Throwable $error,
    ): void {
        $userMessages = [];
        foreach ($request->messages as $msg) {
            if (is_object($msg) && property_exists($msg, 'role') && $msg->role === 'user'
                && property_exists($msg, 'content') && is_string($msg->content) && $msg->content !== '') {
                $userMessages[] = $msg->content;
            }
        }

        $metadata = is_array($request->metadata ?? null) ? $request->metadata : [];
        if ($toolCalls !== []) {
            $metadata['tool_calls'] = array_map(static fn(ToolCall $tc): array => [
                'name' => $tc->name,
                'arguments' => $tc->arguments,
            ], $toolCalls);
        }

        $this->writeRequestLog($request->configuration, [
            'request_type' => 'ToolCallingRequest',
            'usage' => $usage,
            'metadata' => $metadata,
            'duration_ms' => (int)((hrtime(true) - $start) / 1_000_000),
            'success' => $error === null ? 1 : 0,
            'error_message' => $error?->getMessage() ?? '',
            'request_prompt' => implode("\n", $userMessages),
            'request_system_prompt' => $request->systemPrompt,
            'response_content' => $content,
        ]);
    }

    /**
     * @param array{request_type:string, usage:AiUsageStatistics, metadata:array, duration_ms:int, success:int, error_message:string, request_prompt:string, request_system_prompt:string, response_content:string} $payload
     */
    private function writeRequestLog(ProviderConfiguration $configuration, array $payload): void
    {
        $usage = $payload['usage'];
        try {
            GeneralUtility::makeInstance(RequestLogRepository::class, GeneralUtility::makeInstance(ConnectionPool::class))->log([
                'request_type' => $payload['request_type'],
                'provider_identifier' => $configuration->providerIdentifier,
                'configuration_uid' => $configuration->uid,
                'model_requested' => $configuration->model,
                'model_used' => $usage->modelUsed !== '' ? $usage->modelUsed : $configuration->model,
                'extension_key' => (string)($payload['metadata']['extension_key'] ?? $payload['metadata']['extension'] ?? ''),
                'duration_ms' => $payload['duration_ms'],
                'success' => $payload['success'],
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'cached_tokens' => $usage->cachedTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'total_tokens' => $usage->getTotalTokens(),
                'cost' => $usage->cost,
                'system_fingerprint' => $usage->systemFingerprint,
                'raw_usage' => $usage->rawUsage !== [] ? json_encode($usage->rawUsage, JSON_THROW_ON_ERROR) : '',
                'metadata' => json_encode($payload['metadata'], JSON_THROW_ON_ERROR),
                'error_message' => $payload['error_message'],
                'request_prompt' => $payload['request_prompt'],
                'request_system_prompt' => $payload['request_system_prompt'],
                'response_content' => $payload['response_content'],
            ]);
        } catch (\Throwable) {
            // Logging failures must never break the response path.
        }
    }
}
