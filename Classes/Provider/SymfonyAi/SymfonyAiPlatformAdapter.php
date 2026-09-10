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
use B13\Aim\Capability\ImageGenerationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\CredentialRedactor;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\ImageGenerationRequest;
use B13\Aim\Request\Message\AbstractMessage;
use B13\Aim\Request\Message\AssistantMessage;
use B13\Aim\Request\Message\ToolMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\ToolDefinition;
use B13\Aim\Request\ToolResult;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\EmbeddingResponse;
use B13\Aim\Response\GeneratedImage;
use B13\Aim\Response\ImageGenerationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use B13\Aim\Response\ToolCall;
use B13\Aim\Response\ToolCallingResponse;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool as SymfonyTool;
use TYPO3\CMS\Core\Log\LogManager;
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
    EmbeddingCapableInterface,
    ImageGenerationCapableInterface
{
    /** @var array<string, ProviderInterface> Providers cached by configuration key */
    private array $platforms = [];

    private readonly string $maxTokensKey;
    private readonly string $endpointParam;
    private readonly bool $factoryAcceptsEndpoint;
    private readonly bool $factoryAcceptsApiKey;

    /**
     * @param string $factoryClass Fully-qualified class name of the bridge's Factory
     * @param string $factoryParam Name of the factory parameter to pass the config value to ('apiKey' or 'endpoint')
     * @param CredentialRedactor $redactor The redactor is defaulted rather than injected, so the bridge
     *                                     definitions the compiler pass builds stay two-argument.
     */
    public function __construct(
        private readonly string $factoryClass,
        private readonly string $factoryParam = 'apiKey',
        private readonly CredentialRedactor $redactor = new CredentialRedactor(),
    ) {
        $this->maxTokensKey = self::resolveMaxTokensKey($factoryClass);
        $parameters = self::resolveFactoryParameterNames($factoryClass);
        $this->endpointParam = $parameters['endpoint'] ?? 'endpoint';
        $this->factoryAcceptsEndpoint = isset($parameters['endpoint']);
        $this->factoryAcceptsApiKey = isset($parameters['apiKey']);
    }

    /**
     * Which arguments Factory::createProvider() declares, and under which name
     * (endpoint, hostUrl or baseUrl).
     *
     * @return array{endpoint?: string, apiKey?: string}
     */
    private static function resolveFactoryParameterNames(string $factoryClass): array
    {
        $names = [];
        try {
            foreach ((new \ReflectionMethod($factoryClass, 'createProvider'))->getParameters() as $parameter) {
                $name = $parameter->getName();
                if (in_array($name, ['endpoint', 'hostUrl', 'baseUrl'], true)) {
                    $names['endpoint'] ??= $name;
                }
                if ($name === 'apiKey') {
                    $names['apiKey'] = $name;
                }
            }
        } catch (\ReflectionException) {
        }

        return $names;
    }

    /**
     * Every catch block goes through here: the configuration is still in scope,
     * so this is where a quoted credential can be redacted.
     */
    private function describeError(\Throwable $e, ProviderConfiguration $configuration): string
    {
        return 'Symfony AI error: ' . $this->redactor->redact($e->getMessage(), $configuration->apiKey);
    }

    public function processVisionRequest(VisionRequest $request): TextResponse
    {
        $messages = new MessageBag(
            Message::forSystem($request->systemPrompt ?: 'You are a helpful AI assistant that analyzes images.'),
            Message::ofUser(
                $request->prompt,
                Image::fromDataUrl('data:' . $request->mimeType . ';base64,' . $request->imageData),
            ),
        );

        try {
            $platform = $this->getPlatform($request->configuration);
            $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature);
            $result = $platform->invoke($request->configuration->model, $messages, $options);
            return $this->toTextResponse($result, $request->configuration);
        } catch (\Throwable $e) {
            return new TextResponse('', errors: [$this->describeError($e, $request->configuration)]);
        }
    }

    public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
    {
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
            $platform = $this->getPlatform($request->configuration);
            $result = $platform->invoke($request->configuration->model, $messages, $options);
            return $this->toTextResponse($result, $request->configuration);
        } catch (\Throwable $e) {
            return new TextResponse('', errors: [$this->describeError($e, $request->configuration)]);
        }
    }

    public function processTranslationRequest(TranslationRequest $request): TextResponse
    {
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
            $platform = $this->getPlatform($request->configuration);
            $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature);
            $result = $platform->invoke($request->configuration->model, $messages, $options);
            return $this->toTextResponse($result, $request->configuration);
        } catch (\Throwable $e) {
            return new TextResponse('', errors: [$this->describeError($e, $request->configuration)]);
        }
    }

    public function processConversationRequest(ConversationRequest $request): ConversationResponse
    {
        $stream = $request->stream ?? false;
        $messages = $this->buildMessageBag($request->messages, $request->systemPrompt);

        $extra = [];
        if ($request->responseFormat !== null) {
            $extra['response_format'] = $request->responseFormat->toArray();
        }
        if ($stream) {
            $extra['stream'] = true;
        }
        $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature, $extra);

        try {
            $platform = $this->getPlatform($request->configuration);
            $result = $platform->invoke($request->configuration->model, $messages, $options);

            if ($stream) {
                $streamIterator = new StreamChunkIterator(
                    $result->asStream(),
                    $request->configuration,
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
            return new ConversationResponse('', errors: [$this->describeError($e, $request->configuration)]);
        }
    }

    public function processToolCallingRequest(ToolCallingRequest $request): ToolCallingResponse
    {
        $messages = $this->buildMessageBag($request->messages, $request->systemPrompt, true, $request->toolResults);

        $tools = array_map(
            static fn($tool) => new SymfonyTool(
                new ExecutionReference($tool->name),
                $tool->name,
                $tool->description,
                $tool->parameters ?: ['type' => 'object'],
            ),
            $request->tools,
        );

        $extra = ['tools' => $tools];
        if ($request->stream) {
            $extra['stream'] = true;
        }
        $options = $this->buildOptions($request->configuration->model, $request->maxTokens, $request->temperature, $extra);

        try {
            $platform = $this->getPlatform($request->configuration);
            $result = $platform->invoke($request->configuration->model, $messages, $options);

            if ($request->stream) {
                $streamIterator = new StreamChunkIterator(
                    $result->asStream(),
                    $request->configuration,
                );
                return new ToolCallingResponse('', streamIterator: $streamIterator);
            }

            $usage = $this->extractUsage($result, $request->configuration);
            $rawResponse = $this->extractRawResponse($result);
            $content = $this->resolveTextContent($result);
            $toolCalls = $this->rejectUndeclaredToolCalls(
                $this->extractToolCallsFromRawResponse($rawResponse),
                $request->tools,
            );

            return new ToolCallingResponse($content, $toolCalls, $usage, $rawResponse);
        } catch (\Throwable $e) {
            return new ToolCallingResponse('', [], errors: [$this->describeError($e, $request->configuration)]);
        }
    }

    public function processEmbeddingRequest(EmbeddingRequest $request): EmbeddingResponse
    {
        $model = $request->model !== '' ? $request->model : $request->configuration->model;

        $options = [];
        if ($request->dimensions > 0) {
            $options['dimensions'] = $request->dimensions;
        }

        try {
            $platform = $this->getPlatform($request->configuration);
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
            return new EmbeddingResponse(errors: [$this->describeError($e, $request->configuration)]);
        }
    }

    /**
     * Generate one or more images from a prompt, optionally guided by a
     * reference image (image-to-image / style transfer).
     */
    public function processImageGenerationRequest(ImageGenerationRequest $request): ImageGenerationResponse
    {

        $options = $request->options;
        if ($request->count > 1) {
            $options['n'] = $request->count;
        }
        if ($request->referenceImageData !== '') {
            $options['image'] = Image::fromDataUrl(
                'data:' . $request->referenceMimeType . ';base64,' . $request->referenceImageData,
            );
        }

        try {
            $platform = $this->getPlatform($request->configuration);
            $result = $platform->invoke($request->configuration->model, $request->prompt, $options);
            $images = $this->extractImages($result);
            if ($images === []) {
                return new ImageGenerationResponse(errors: ['Provider returned no image data.']);
            }

            $usage = $this->extractUsage($result, $request->configuration);
            $rawResponse = $this->extractRawResponse($result);
            return new ImageGenerationResponse($images, $usage, $rawResponse);
        } catch (\Throwable $e) {
            return new ImageGenerationResponse(errors: [$this->describeError($e, $request->configuration)]);
        }
    }

    /**
     * @return list<GeneratedImage>
     */
    private function extractImages(object $result): array
    {
        $resolved = method_exists($result, 'getResult') ? $result->getResult() : $result;
        $parts = $resolved instanceof MultiPartResult ? $resolved->getContent() : [$resolved];

        $images = [];
        foreach ($parts as $part) {
            if ($part instanceof BinaryResult) {
                $images[] = GeneratedImage::fromBase64($part->toBase64(), $part->getMimeType() ?? 'image/png');
            }
        }
        return $images;
    }

    /**
     * Lazily create and cache a Provider instance per provider configuration.
     */
    private function getPlatform(ProviderConfiguration $config): ProviderInterface
    {
        $cacheKey = $config->uid > 0 ? (string)$config->uid : md5($config->apiKey . $config->endpoint . $config->model);
        if (!isset($this->platforms[$cacheKey])) {
            $factoryClass = $this->factoryClass;
            $this->platforms[$cacheKey] = $factoryClass::createProvider(...$this->buildFactoryArguments($config));
        }
        return $this->platforms[$cacheKey];
    }

    /**
     * A bridge declaring both parameters gets both. Unmigrated rows still carry
     * their endpoint in api_key, so the credential is dropped when it would
     * only repeat the endpoint.
     *
     * @return array<string, string>
     */
    private function buildFactoryArguments(ProviderConfiguration $config): array
    {
        $arguments = [];
        if ($this->factoryAcceptsEndpoint && $config->endpoint !== '') {
            $arguments[$this->endpointParam] = $config->getRequestEndpoint();
        }
        // A credential that belongs in the URL is already in it, and such a host
        // rejects a bearer token, so it must not go out twice.
        if ($this->factoryAcceptsApiKey
            && $config->apiKey !== ''
            && $config->apiKey !== $config->endpoint
            && !$config->expectsCredentialInUrl()
        ) {
            $arguments['apiKey'] = $config->apiKey;
        }

        if ($arguments === []) {
            // Fall back to one argument so the failure surfaces as the
            // provider's own error, not an ArgumentCountError.
            $arguments = $this->factoryParam === 'endpoint'
                ? [$this->endpointParam => $config->endpoint ?: $config->apiKey]
                : ['apiKey' => $config->apiKey];
        }

        return $arguments;
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
     * Drops tool calls naming a tool this request never declared. Arguments are
     * left alone; validating those is the consumer's job.
     *
     * @param list<ToolCall> $toolCalls
     * @param list<ToolDefinition> $tools
     * @return list<ToolCall>
     */
    private function rejectUndeclaredToolCalls(array $toolCalls, array $tools): array
    {
        if ($toolCalls === []) {
            return $toolCalls;
        }

        $declared = array_map(static fn(ToolDefinition $tool): string => $tool->name, $tools);

        $kept = array_values(array_filter(
            $toolCalls,
            static fn(ToolCall $call): bool => in_array($call->name, $declared, true),
        ));

        if (count($kept) !== count($toolCalls)) {
            // Loud rather than silent, so a bridge reporting names in an unexpected shape is visible.
            $dropped = array_diff(
                array_map(static fn(ToolCall $call): string => $call->name, $toolCalls),
                $declared,
            );
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning(sprintf(
                    'Dropped %d tool call(s) naming undeclared tool(s) "%s". Declared: "%s".',
                    count($toolCalls) - count($kept),
                    implode('", "', $dropped),
                    implode('", "', $declared),
                ));
        }

        return $kept;
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
     * With $nativeToolProtocol enabled, assistant messages carrying tool calls
     * and tool(-result) messages are mapped to Symfony AI's native message
     * types, so each bridge serialises its provider-specific round-trip shape
     * (tool_use/tool_result blocks on Anthropic, function_call/
     * function_call_output items on the OpenAI Responses API, functionCall/
     * functionResponse parts on Gemini, nested tool_calls/tool messages on the
     * Chat Completions dialect).
     *
     * Native tool blocks are only valid when the request also defines tools —
     * providers reject or blank a tool exchange without a tools option. Only
     * processToolCallingRequest() enables this; requests without tools keep
     * flattening tool messages to plain text turns.
     *
     * Providers require the assistant turn that requested a tool call to
     * precede the corresponding result, so callers passing tool results must
     * keep that assistant message (with its tool calls) in the history.
     *
     * @param list<AbstractMessage> $aiMessages
     * @param list<ToolResult> $toolResults
     */
    private function buildMessageBag(
        array $aiMessages,
        string $systemPrompt,
        bool $nativeToolProtocol = false,
        array $toolResults = [],
    ): MessageBag {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = Message::forSystem($systemPrompt);
        }
        // Tool calls seen in assistant turns, keyed by id, so tool results can
        // reference the full call (Gemini needs the tool name in the response).
        $seenToolCalls = [];
        foreach ($aiMessages as $msg) {
            $content = is_string($msg->content) ? $msg->content : '';
            if ($nativeToolProtocol && $msg instanceof AssistantMessage && $msg->toolCalls !== []) {
                $parts = $content !== '' ? [$content] : [];
                foreach ($msg->toolCalls as $call) {
                    $seenToolCalls[$call->id] = $this->toSymfonyToolCall($call);
                    $parts[] = $seenToolCalls[$call->id];
                }
                $messages[] = Message::ofAssistant(...$parts);
                continue;
            }
            if ($nativeToolProtocol && $msg instanceof ToolMessage) {
                $messages[] = Message::ofToolCall(
                    $seenToolCalls[$msg->toolCallId] ?? new SymfonyToolCall($msg->toolCallId, ''),
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
        foreach ($toolResults as $toolResult) {
            $messages[] = Message::ofToolCall(
                $seenToolCalls[$toolResult->toolCallId]
                    ?? new SymfonyToolCall($toolResult->toolCallId, $toolResult->name),
                $toolResult->output,
            );
        }
        return new MessageBag(...$messages);
    }

    private function toSymfonyToolCall(ToolCall $call): SymfonyToolCall
    {
        return new SymfonyToolCall($call->id, $call->name, $call->getDecodedArguments());
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
     * Resolve the max-tokens option key expected by a Symfony AI bridge.
     *
     * Each bridge keeps the option naming convention of its underlying API:
     *   - Gemini uses camelCase (Google REST API: "maxOutputTokens")
     *   - OpenAI / OpenResponses use snake_case "max_output_tokens"
     *   - Anthropic / Mistral / Ollama and most others use legacy "max_tokens"
     */
    public static function resolveMaxTokensKey(string $factoryClass): string
    {
        if (str_contains($factoryClass, '\\Bridge\\Gemini\\')) {
            return 'maxOutputTokens';
        }
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
}
