# Using AiM from your extension

The three ways to dispatch a request, from the one-line proxy call to full pipeline access, and what capabilities an extension can ask for.

[Back to the README](../README.md)

## Trying AiM from the command line

Once a provider configuration exists, you can fire requests without writing an extension first. The `aim:test` command sends a one-off request through the full pipeline and reports the response, model used, token usage, cost, timing, and whether a request-log row was written:

```bash
# Text generation (default capability)
vendor/bin/typo3 aim:test text --prompt "Write a haiku about TYPO3"

# Conversation, against a specific provider
vendor/bin/typo3 aim:test conversation -p "anthropic:*" --prompt "Explain dependency injection"

# Translation
vendor/bin/typo3 aim:test translate --prompt "Hello world" --from English --to German

# Embeddings
vendor/bin/typo3 aim:test embed --prompt "TYPO3 is an open-source CMS"
```

The capability is a positional argument (`text`, `conversation`, `translate`, or `embed`; defaults to `text`). Options:

| Option | Purpose |
|---|---|
| `--prompt` | The prompt / text to send |
| `--provider` / `-p` | Provider notation (`openai:gpt-4o`, `anthropic:*`); defaults to the configured default |
| `--site` | Resolve the provider from a site's `settings.yaml` instead of the database; takes precedence over `--provider`. See [Configuring a provider in site settings instead](#configuring-a-provider-in-site-settings-instead) for the keys |
| `--system-prompt` | Optional system prompt |
| `--max-tokens` | Token limit for the response |
| `--from` / `--to` | Source / target language (translate only) |

Because it runs through the real pipeline, every call also lands in the request log. A quick way to see logging, cost tracking, smart routing, and grading in action before integrating the API into your own code.

## Calling AiM from PHP

### Tier 1: Proxy (recommended)

The simplest way. Extensions never see providers, configurations, or API keys:

```php
use B13\Aim\Ai;

public function __construct(
    private readonly Ai $ai,
) {}

// Vision (e.g. alt text generation)
$response = $this->ai->vision(
    imageData: base64_encode($fileContent),
    mimeType: 'image/jpeg',
    prompt: 'Generate alt text for this image',
    extensionKey: 'my_extension',
);
echo $response->content;

// Text generation
$response = $this->ai->text(
    prompt: 'Write a meta description for a bakery website.',
    maxTokens: 160,
    extensionKey: 'my_extension',
);

// Translation

$response = $this->ai->translate(
    text: 'Hello world',
    sourceLanguage: 'English',
    targetLanguage: 'German',
    extensionKey: 'my_extension',
);

// Conversation
$response = $this->ai->conversation(
    messages: [new UserMessage('What is TYPO3?')],
    systemPrompt: 'You are a CMS expert.',
    extensionKey: 'my_extension',
);

// Embeddings
$response = $this->ai->embed(
    input: 'TYPO3 is an open-source CMS',
    dimensions: 256,
    extensionKey: 'my_extension',
);

// Image generation
$response = $this->ai->generateImage(
    prompt: 'A minimalist header illustration of a lighthouse at sunset',
    options: ['size' => '1536x1024', 'quality' => 'high'], // provider-specific, passed through as-is
    extensionKey: 'my_extension',
);
if ($response instanceof \B13\Aim\Response\ImageGenerationResponse) {
    foreach ($response->images as $image) {
        if ($image->isUrl()) {
            // Some providers return a temporary URL instead of the bytes.
            file_put_contents('header.png', file_get_contents($image->url));
        } else {
            file_put_contents('header.png', base64_decode($image->data));
        }
    }
}
```

#### Image generation with a reference image (style transfer)

Every editor prompting an image generator on their own produces a different look, inconsistent styles, colors, and composition scattered across the site. Instead, pass an existing on-brand image as a **style reference** alongside the prompt. AiM asks the provider to generate an image-to-image edit guided by it, so headers, teasers, and illustrations stay visually consistent site-wide instead of looking like they came from ten different tools:

```php
$response = $this->ai->generateImage(
    prompt: 'A lighthouse at sunset, for the "About us" page header',
    referenceImageData: base64_encode(file_get_contents('brand-style-reference.png')),
    referenceMimeType: 'image/png',
    options: ['size' => '1536x1024'],
    extensionKey: 'my_extension',
);
```

`options` is a generic pass-through bag since valid keys/values differ per provider (e.g. OpenAI also supports `background` for transparent images and `output_format` for png/jpeg/webp). The same option is available on the fluent builder via `->referenceImage($imageData, $mimeType)` (see [Tier 2](#tier-2-fluent-builder) below).

#### Provider preference

Extensions can request a specific provider without hardcoding configuration UIDs:

```php
// Use OpenAI, admin picks the model
$response = $this->ai->text(
    prompt: 'Summarize this.',
    provider: 'openai:*',
    extensionKey: 'my_extension',
);

// Use a specific model
$response = $this->ai->vision(
    imageData: $data,
    mimeType: 'image/jpeg',
    prompt: 'Describe this image',
    provider: 'openai:gpt-4.1',
    extensionKey: 'my_extension',
);
```

A requested provider is the one the request is sent to, and heads its fallback chain; other capable configurations are only tried if it fails. If the requested provider is unavailable (not installed, no configuration), AiM falls back to the default with a logged warning.

### Tier 2: Fluent Builder

More control over parameters, still provider-agnostic:

```php
$response = $this->ai->request()
    ->vision($imageData, 'image/jpeg')
    ->prompt('Generate alt text for this image')
    ->systemPrompt('You are an accessibility expert.')
    ->maxTokens(100)
    ->temperature(0.3)
    ->provider('openai:*')
    ->from('my_extension')
    ->send();
```

The same builder covers image generation, including the reference-image style transfer shown above:

```php
$response = $this->ai->request()
    ->image()
    ->prompt('A lighthouse at sunset, for the "About us" page header')
    ->referenceImage($imageData, 'image/png')
    ->options(['size' => '1536x1024'])
    ->from('my_extension')
    ->send();
```

### Tier 3: Direct pipeline access

Full control. You choose the provider, build the request, and dispatch through the pipeline:

```php
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Request\TextGenerationRequest;

$resolvedProvider = $this->providerResolver->resolveForCapability(
    TextGenerationCapableInterface::class
);

$request = new TextGenerationRequest(
    configuration: $resolvedProvider->configuration,
    prompt: 'Write a meta description for a bakery website.',
    maxTokens: 160,
    metadata: ['extension' => 'my_extension'],
);

$response = $this->pipeline->dispatch($request, $resolvedProvider);
```

All three tiers flow through the same middleware chain: Logging, governance, cost tracking, and events always fire regardless of how the request was initiated.

### Structured output (JSON Schema)

```php
use B13\Aim\Request\ResponseFormat;

$response = $this->ai->text(
    prompt: 'Extract the product name and price from: "The MacBook Pro costs $2449.99"',
    responseFormat: ResponseFormat::jsonSchema('product', [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'price' => ['type' => 'number'],
        ],
        'required' => ['name', 'price'],
        'additionalProperties' => false,
    ]),
    extensionKey: 'my_extension',
);
$data = json_decode($response->content, true);
```

### Tool calling

For simple cases, `$ai->toolCalling()` is the recommended Tier 1 entry point; no manual provider resolution or pipeline dispatch needed:

```php
use B13\Aim\Request\ToolDefinition;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Response\ToolCallingResponse;

$response = $this->ai->toolCalling(
    messages: [new UserMessage('What is the weather in Berlin?')],
    tools: [
        new ToolDefinition(
            name: 'get_weather',
            description: 'Get current weather for a city',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required' => ['city'],
                'additionalProperties' => false,
            ],
            strict: true,
        ),
    ],
    extensionKey: 'my_extension',
);
if ($response instanceof ToolCallingResponse && $response->requiresToolExecution()) {
    foreach ($response->toolCalls as $toolCall) {
        // $toolCall->name, $toolCall->getDecodedArguments()
    }
}
```

The `instanceof` check is necessary because `toolCalling()` returns the base `TextResponse` type; governance middlewares (access control, budgets, rate limits) can short-circuit with a plain `TextResponse` before the provider is ever called, so a narrower return type would risk a `TypeError` on a denied request.

For full control over the request (custom `maxTokens`, direct fallback-chain access, etc.), Tier 3 direct pipeline access is still available:

```php
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\ToolDefinition;
use B13\Aim\Request\Message\UserMessage;

$request = new ToolCallingRequest(
    configuration: $resolvedProvider->configuration,
    messages: [new UserMessage('What is the weather in Berlin?')],
    tools: [
        new ToolDefinition(
            name: 'get_weather',
            description: 'Get current weather for a city',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required' => ['city'],
                'additionalProperties' => false,
            ],
            strict: true,
        ),
    ],
);

$response = $this->pipeline->dispatch($request, $resolvedProvider);
if ($response->requiresToolExecution()) {
    foreach ($response->toolCalls as $toolCall) {
        // $toolCall->name, $toolCall->getDecodedArguments()
    }
}
```

Tool schemas are serialized natively per provider (OpenAI, Anthropic, Gemini, ...) via the underlying Symfony AI bridge. You never need to worry about wire-format differences between providers.

#### Multi-turn: feeding tool results back

A single round only gets you the model's *request* to call a tool. To let the model use the result, execute the tool yourself, then send a follow-up `ToolCallingRequest` carrying the assistant's tool-call message plus the result:

```php
use B13\Aim\Request\Message\AssistantMessage;
use B13\Aim\Request\ToolResult;

// $response is the ToolCallingResponse from the first round above.
$followUp = new ToolCallingRequest(
    configuration: $resolvedProvider->configuration,
    messages: [
        new UserMessage('What is the weather in Berlin?'),
        new AssistantMessage($response->content, $response->toolCalls),
    ],
    tools: [/* same tool definitions as the first round */],
    toolResults: array_map(
        static fn($toolCall) => new ToolResult(
            toolCallId: $toolCall->id,
            name: $toolCall->name,
            output: json_encode(['temperature' => 21, 'condition' => 'sunny']), // your tool's actual result
        ),
        $response->toolCalls,
    ),
);

$response = $this->pipeline->dispatch($followUp, $resolvedProvider);
// $response->content now contains the model's answer using the tool result.
// Repeat while $response->requiresToolExecution() for agentic, multi-step tool use.
```

Keep looping (execute tool calls → send `toolResults` → check `requiresToolExecution()` again) until the model returns plain content. Always cap the number of rounds: nothing in AiM stops a model from calling tools indefinitely.

## Capabilities

Each provider implements one or more capability interfaces:

| Interface | Request | Response | Use Case |
|---|---|---|---|
| `VisionCapableInterface` | `VisionRequest` | `TextResponse` | Image analysis, alt text generation |
| `ConversationCapableInterface` | `ConversationRequest` | `ConversationResponse` | Conversations, chatbots, multi-turn dialogs |
| `TextGenerationCapableInterface` | `TextGenerationRequest` | `TextResponse` | Content generation, summaries |
| `TranslationCapableInterface` | `TranslationRequest` | `TextResponse` | Text translation |
| `ToolCallingCapableInterface` | `ToolCallingRequest` | `ToolCallingResponse` | Agentic workflows, function calling |
| `EmbeddingCapableInterface` | `EmbeddingRequest` | `EmbeddingResponse` | Vector embeddings, semantic search, RAG |

### Model-level capabilities

Providers can declare per-model capabilities via `modelCapabilities`. Models listed get only the specified capabilities. Unlisted models inherit all provider capabilities except specialized ones (e.g. embedding-only models).

```php
#[AsAiProvider(
    identifier: 'openai',
    supportedModels: ['gpt-4o' => 'GPT-4o', 'text-embedding-3-small' => 'Embeddings'],
    modelCapabilities: [
        'text-embedding-3-small' => [EmbeddingCapableInterface::class],
        // gpt-4o inherits all capabilities EXCEPT embedding
    ],
)]
```

### Auto model switch

When a provider config has `gpt-4o` but an embedding request comes in, AiM automatically switches to the cheapest capable model (e.g. `text-embedding-3-small`) using the same API key. The selection is data-driven: if historical cost data exists in the request log, AiM picks the cheapest model with a good success rate. Otherwise it falls back to the most specialized model.

The switch is:
- **Logged** with `model_requested`, `model_used`, and reroute reason
- **Controllable** at three levels:

| Level | Setting | Default |
|---|---|---|
| Per config | `auto_model_switch` toggle in TCA | On |
| Per user/group | `aim.autoModelSwitch = 0` in TSconfig | On |
| Admin | Always allowed | - |
