# AiM - Intelligent AI Proxy for TYPO3

AiM is the central AI layer for TYPO3. Extensions describe what they need. AiM decides which provider and model to use, routes through a middleware pipeline, and returns the result. Built for TYPO3 v12, v13, and v14.

> **New to AiM?** Read the [Introduction](Documentation/Introduction.md) for a non-technical overview of what AiM does, why it exists, and how it works for administrators and extension developers.

> **Alpha state.** AiM is under active development. The API is functional but may change before 1.0. We'd love your feedback: [open an issue](https://github.com/b13/aim/issues) or reach out at [b13.com](https://b13.com).

![AiM module overview](Documentation/Images/request-log-dark.png)

## Quick start

```php
use B13\Aim\Ai;

public function __construct(private readonly Ai $ai) {}

$response = $this->ai->vision(
    imageData: base64_encode($fileContent),
    mimeType: 'image/jpeg',
    prompt: 'Generate alt text for this image',
    extensionKey: 'my_extension',
);
echo $response->content; // "A golden retriever playing fetch in a sunny park"
```

A few lines to add AI to any TYPO3 extension. No API keys in your code, no provider lock-in, full logging and cost tracking out of the box.

## Key features

**For extension developers:**
- Simple proxy API (`$ai->vision()`, `$ai->text()`, `$ai->translate()`, `$ai->embed()`, `$ai->generateImage()`)
- Fluent builder for advanced parameters
- Image generation with reference-image style transfer
- Direct pipeline access for full control
- Structured output (JSON Schema), tool calling, streaming

**For administrators:**
- Backend modules for provider management, request monitoring, and prompt/tone preview
- Disable specific models per provider via clickable badges
- Budget limits and rate limiting per user (including admins as a safety net)
- Privacy levels (standard / reduced / none) per provider
- Provider group restrictions and capability permissions via native TYPO3 mechanisms
- LLM grading: score response quality with a second model acting as a judge
- Tone of voice / system prompts: page-tree inherited, with a global fallback and optional per-provider addendum
- Voice calibration: derive a tone-of-voice fragment from real page content, interactively or via a site-wide crawl command

**Under the hood:**
- Zero provider dependencies. Install Symfony AI bridge packages as needed.
- Auto-discovery of installed bridges (OpenAI, Anthropic, Gemini, Mistral, Ollama, etc.)
- Capability-based routing with model-level awareness
- Auto model switch: one config covers all capabilities
- Smart routing: routes simple prompts to cheaper models based on historical cost, reliability, and (with grading) quality data
- Fallback chains: automatic retry with alternative providers on failure
- 10-layer middleware pipeline: retry, access control, smart routing, capability validation, system prompt resolution, grading, logging, cost tracking, events, dispatch

## Installation

```bash
composer require b13/aim
```

AiM has **zero AI provider dependencies**. Install provider bridges as needed:

```bash
# For OpenAI
composer require symfony/ai-open-ai-platform

# For local models via Ollama
composer require symfony/ai-ollama-platform

# For Anthropic, Gemini, Mistral, etc.
composer require symfony/ai-anthropic-platform
composer require symfony/ai-gemini-platform
composer require symfony/ai-mistral-platform
```

Any installed `symfony/ai-*-platform` package is **auto-discovered** at container compile time. Models, capabilities, and features are read from the bridge's `ModelCatalog` automatically.

After installation, create a provider configuration in the backend (Admin Tools > AiM > Providers) with your API key and preferred model.

> **Local providers (Ollama, LM Studio):** The *API Key* field doubles as the endpoint URL. Enter `http://localhost:11434` (Ollama) or `http://localhost:1234` (LM Studio) instead of a key. The available models are then fetched live from that endpoint.

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
| `--site` | Resolve the provider from a site's `settings.yaml` instead of the database; takes precedence over `--provider` |
| `--system-prompt` | Optional system prompt |
| `--max-tokens` | Token limit for the response |
| `--from` / `--to` | Source / target language (translate only) |

Because it runs through the real pipeline, every call also lands in the request log. A quick way to see logging, cost tracking, smart routing, and grading in action before integrating the API into your own code.

## Usage

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

If the requested provider is unavailable, AiM falls back to the default with a logged warning.

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

## Registering a Custom Provider

Any extension can add AI providers. Create a class implementing `AiProviderInterface` plus any capability interfaces, and annotate it with `#[AsAiProvider]`:

```php
use B13\Aim\Attribute\AsAiProvider;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Provider\AiProviderInterface;

#[AsAiProvider(
    identifier: 'my-provider',
    name: 'My AI Provider',
    description: 'Custom provider for my use case',
    supportedModels: [
        'my-model-v1' => 'My Model v1',
        'my-model-v2' => 'My Model v2',
    ],
    features: [
        'supportsStructuredOutput' => true,
        'supportsStreaming' => true,
        'maxContextWindow' => 128000,
    ],
)]
class MyProvider implements AiProviderInterface, TextGenerationCapableInterface, VisionCapableInterface
{
    public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse { ... }
    public function processVisionRequest(VisionRequest $request): TextResponse { ... }
}
```

The provider is auto-discovered via the PHP attribute. No manual registration needed.

## Symfony AI Integration

AiM auto-discovers any installed Symfony AI bridge package (`symfony/ai-*-platform`). For each bridge:

1. Reads the PSR-4 namespace from the package's `composer.json`
2. Instantiates the bridge's `ModelCatalog` to read models and per-model capabilities
3. Maps Symfony AI `Capability` enums to AiM capability interfaces
4. Sanitizes model names for TCA compatibility (no colons)
5. Detects the factory authentication parameter via reflection (`apiKey` vs `endpoint`)
6. Registers a `SymfonyAiPlatformAdapter` as an AiM provider

Install a bridge, flush caches. The provider appears automatically in the backend module with all its models.

## Governance & Access Control

AiM provides a complete governance system for AI usage, built on native TYPO3 mechanisms.

### API key encryption

Provider API keys stored in `tx_aim_configuration.api_key` are encrypted using a key derived from `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`.

| TYPO3 version | Cipher | Implementation |
|---|---|---|
| v14+ | XChaCha20-Poly1305 AEAD | Core `\TYPO3\CMS\Core\Crypto\Cipher\CipherService` |
| v12 / v13 | XSalsa20-Poly1305 secretbox | Local libsodium implementation (CipherService not yet available) |

Stored values carry a version prefix (`aim:enc:v1:` for the v12/v13 path, `aim:enc:v2:` for the v14 path) so decryption auto-selects the right routine even after an upgrade. A DataHandler hook encrypts on save; the repository decrypts on read, only where the plaintext is actually needed to call the provider. Legacy plaintext rows from earlier AiM versions are migrated via the **"[AiM] Encrypt stored provider API keys"** upgrade wizard in the Install Tool.

**Once encrypted, a key is never shown again, anywhere in the backend.** The edit form's `HideApiKey` FormDataProvider blanks the field and switches it to a masked password input instead of decrypting it for display; saving with the field left empty keeps the existing key unchanged. The Providers overview and the connection-verification response both strip/redact the key before they ever reach a view or a JSON payload, so a decrypted secret never round-trips into the DOM, a log line, or an error message.

For providers that put an **endpoint URL** in the `api_key` field instead of a real secret (Ollama, LM Studio, self-hosted OpenAI-compatible proxies), AiM detects the `http://` / `https://` prefix and skips both encryption and masking: the URL stays plaintext and fully visible, since it isn't a secret.

If `SYS/encryptionKey` is rotated, existing API keys can no longer be decrypted with the new key. Run the rotation command *before* the rotation takes effect, or right after with the old value still in hand:

```bash
vendor/bin/typo3 aim:rotateApiKeys --old-key='<previous SYS/encryptionKey value>'
```

The command decrypts each stored key with the supplied old value, re-encrypts with the current one, and reports the result. It is idempotent (re-running with the same old key is a no-op) and aborts without writes if any row cannot be decrypted with the supplied value. Add `--dry-run` to preview.

Without the previous key value, encrypted API keys cannot be recovered. This is by design. Save the old `SYS/encryptionKey` somewhere safe before rotating.

### Provider restrictions

Restrict provider configurations to specific backend user groups via the `be_groups` field on each configuration record. Only members of the listed groups (or admins) can use that configuration.

### Capability permissions

Register AiM capability permissions in backend user groups (Access > Custom Options):

- `aim:capability_text`: Text generation
- `aim:capability_vision`: Vision requests
- `aim:capability_translation`: Translations
- `aim:capability_conversation`: Conversations
- `aim:capability_embedding`: Embeddings
- `aim:capability_toolcalling`: Tool calling

**Permissive by default**: if no AiM permissions are configured in any group, all capabilities are allowed. Once any `aim:` permission is set, only explicitly granted capabilities are allowed.

### Budget limits (UserTSconfig)

```typoscript
aim {
  budget {
    period = monthly
    maxCost = 50.00
    maxTokens = 500000
    maxRequests = 1000
  }
  rateLimit {
    requestsPerMinute = 10
  }
}
```

Budgets are tracked per user in rolling periods (daily/weekly/monthly) in `tx_aim_usage_budget`. When exceeded, requests are blocked with a clear error message.

**Budgets and rate limits apply to all users, including admins.** Admins skip provider group restrictions and capability permissions, but budgets and rate limits act as a safety net against accidental cost overruns. An admin can set their own limits via UserTSconfig and will be blocked when exceeded.

### Privacy levels

Each provider configuration has a privacy level:

| Level | Behavior |
|---|---|
| `standard` | Full logging: prompt, response, tokens, cost |
| `reduced` | Metadata only: tokens, cost, model, duration. No prompt/response content |
| `none` | No logging at all |

Users can escalate (but never downgrade) the privacy level via TSconfig:

```typoscript
aim.privacyLevel = reduced
```

The strictest level between the config and the user always wins.

### Rerouting protection

Set `rerouting_allowed = 0` on a provider configuration to prevent the smart router from rerouting requests away from or to that configuration. Combined with `be_groups`, this ensures confidential data (e.g. HR data on a local Ollama) stays on the designated model.

## Tone of Voice / System Prompts

Two middlewares automatically compose the final prompt sent to the provider, from up to five layers, in this order:

1. **The caller's own prompt**: the domain-specific instruction a consuming extension already sets (e.g. `descriptive_images`' "generate alt text...", or the creative prompt for image generation). Unchanged.
2. **The resolved tone of voice**: page-tree prompt fragments, DB and Page-TSconfig sourced (see below), or the global fallback when there's no page context.
3. **User/Group-TSconfig-assigned fragments**: see below. Apply whenever a backend user is present, regardless of page context.
4. **Code-registered fragments**: see below. Apply regardless of page context.
5. **The provider-specific addendum**: `system_prompt_addition` on the `tx_aim_configuration` row actually used (after any rerouting/fallback), for provider-specific quirks or instructions.

Layers 1-4 are composed by `TonePromptCompositionMiddleware` (priority 80); layer 5 by a separate `ProviderAddendumMiddleware` (priority 10). They're split across two different points in the pipeline on purpose:

- The addendum is tied to a specific provider configuration, so it must run after `CapabilityValidationMiddleware`/`SmartRoutingMiddleware` have settled on the *final* one (rerouting/downgrade already happened); same reasoning the original single combined middleware had.
- Tone/user/registry fragments don't depend on the provider at all, so `TonePromptCompositionMiddleware` runs *before* `SmartRoutingMiddleware` instead. This matters: `SmartRoutingMiddleware`'s complexity classification only ever looks at the caller's bare task prompt. If tone composition ran later (as it originally did, in one combined middleware at priority 10), a short/simple task prompt could get waved through to a cheaper/weaker model even though the actual outbound payload (once tone-of-voice fragments were added) was large and instruction-heavy. Running tone composition first means smart routing sees the real prompt.

Empty layers are skipped; the parts are joined with a blank line, and exact duplicates across layers are only sent once, including across the two middlewares (`ProviderAddendumMiddleware` skips the addendum if it exactly duplicates a part `TonePromptCompositionMiddleware` already included, via `RequestContext::$composedPromptParts`). For chat-shaped requests (text, vision, translation, conversation, tool calling) all five layers are composed into the system prompt. **Image generation has no system-role channel**: the same layers are spliced into `prompt` instead, after the caller's own creative prompt (e.g. to enforce a watermark or consistent brand style).

Any caller can opt out of all of this entirely:

```php
$response = $ai->text('Diagnostic ping', disableSystemPromptComposition: true);

// Fluent builder
$response = $ai->request()->text()->prompt('...')->disableSystemPromptComposition()->send();
```

Or replace layers 2-5 with caller-supplied content for one specific call, without giving up composition altogether: the caller's own base prompt (layer 1) is still combined with the given fragment(s), but page tone, user/registry fragments, and the provider addendum are all skipped:

```php
$response = $ai->text('Summarize this article: ...', systemPromptOverride: 'Use a playful tone just for this call.');
$response = $ai->text('...', systemPromptOverride: ['First instruction.', 'Second instruction.']); // string or array

// Fluent builder
$response = $ai->request()->text()->prompt('...')->systemPromptOverride('Use a playful tone.')->send();
```

`disableSystemPromptComposition` and `systemPromptOverride` are two separate parameters rather than one polymorphic one: PHP 8.1 (still supported here) doesn't allow `false` as a standalone type in a union, so a single `string|array|false|null` signature isn't possible until PHP 8.2. If both are set, `disableSystemPromptComposition` wins (pure passthrough, `systemPromptOverride` is ignored).

### Full parity for an extension with its own tone-of-voice system

If another extension already has its own page-level tone-of-voice/system-prompt configuration and wants to dispatch through AiM anyway (to still get provider abstraction, governance, smart routing, fallback, and logging "for free"), `disableSystemPromptComposition` alone gets most of the way there, but skips AiM's provider-specific addendum too. To keep that as well, without AiM's own resolution getting in the way, ship a middleware of your own rather than composing the addendum outside the pipeline:

```php
#[AsAiMiddleware(priority: 5)] // anywhere below 50, same reasoning TonePromptCompositionMiddleware/
                                // ProviderAddendumMiddleware have: CapabilityValidationMiddleware/
                                // SmartRoutingMiddleware must have already settled on the final provider
final class MyExtensionToneMiddleware implements AiMiddlewareInterface
{
    public function process($request, $provider, $configuration, $next): TextResponse
    {
        // disableSystemPromptComposition: true already made both of AiM's
        // own middlewares no-op; that flag doubles as "someone else is
        // handling this." $configuration here is guaranteed final/
        // post-rerouting, the same guarantee AiM's own middlewares get.
        if ($request instanceof SupportsSystemPromptInterface && $request->isAutomaticPromptCompositionDisabled()) {
            $tone = $this->myOwnResolver->resolve($request->getPageId());
            $composed = PromptComposer::compose([$request->getSystemPrompt(), $tone, $configuration->systemPromptAddition]);
            $request = $request->withSystemPrompt($composed);
        }
        return $next->handle($request, $provider, $configuration);
    }
}
```

Calling code stays exactly what's shown above (`disableSystemPromptComposition: true`); the middleware is the only new piece. This is possible with the public API as it stands today; nothing on AiM's side needs to change for a consumer to do this.

### Page-tree prompt fragments (DB)

Every page has a repeatable **AI** tab (`tx_aim_prompt_fragment`, an inline/IRRE field): add as many named fragments as needed, each with:

- **Prompt**: the actual instruction text.
- **Examples**: optional few-shot text, appended after the prompt (`"\n\nExamples:\n" . examples`) whenever this fragment is included. Pairing an instruction with concrete example text steers output more reliably than adjective-laden prose alone. Travels with its own fragment, so fragments with different tones never have their examples mixed together.
- **Scope**: one or more capabilities it applies to, via checkboxes (`Text Generation`, `Vision`, `Translation`, `Conversation`, `Tool Calling`, `Image Generation`, all six checked by default on a new fragment). A fragment matches a request if any of its checked capabilities matches the request's own. Stored comma-separated, the same convention TYPO3 core uses for this shape (`be_groups.file_permissions`).
- **Inherit to subpages** (default on): when enabled, this fragment also applies to every page below this one, in addition to this page itself.

Inheritance is **additive per fragment**, not a single overridable value: a page's own fragments always apply to itself; an inheriting ancestor's fragments are added on top. A subpage adding its own fragment *supplements* what it inherited; it never silently drops an ancestor's fragment. Composition order is root-to-target, so general tone reads before page-specific instructions.

A page can also check **"Disable inherited prompt fragments"** to skip every ancestor fragment for that page specifically (its own fragments still apply), useful for a microsite/campaign section that must not pick up the corporate tone. This is page-local, not a subtree boundary: the page's own children are unaffected and keep inheriting from the original ancestors normally.

DB fragment inheritance stops at the nearest `is_siteroot` ancestor: in a nested-site install (one site's page tree living under another site's), a page's own fragments never leak into an unrelated site. Page TSconfig fragments deliberately don't get this treatment; `getPagesTSconfig()` has never respected site boundaries, and that's an established, technical-audience convention this extension doesn't override.

DB fragments respect workspace overlays for edits to *existing* fragments: a fragment modified in a workspace is visible when resolving within that workspace, via the standard `PageRepository::versionOL()` idiom. A fragment created entirely new within a workspace (no live counterpart yet) won't appear until published (a narrower, known gap rather than full versioning-aware listing).

To have a request resolve against a page, pass `pageId`:

```php
$response = $ai->text('Summarize this article: ...', pageId: $pageUid);

// Fluent builder, works for image generation too
$response = $ai->request()->image()->prompt('A mountain landscape at sunset')->forPage($pageUid)->send();
```

Not translatable: the record has no language field, since nothing in the resolution pipeline is language-aware yet.

### Page-tree prompt fragments (TSconfig)

The same tone-of-voice layer can also be authored via Page TSconfig; merged directly alongside the DB fragments above, after them, for the same page:

```
aim.promptFragments.watermark.prompt = Always add a small diagonal watermark reading "DRAFT".
aim.promptFragments.watermark.scope = imageGeneration
aim.promptFragments.brandVoice.prompt = Write in a warm, second-person voice.
```

`scope` defaults to `all` when omitted. Since this is plain Page TSconfig, TYPO3's own cascade and `>` clear operator apply as usual: `aim.promptFragments >` on a page resets everything inherited from above for this source specifically (the DB "disable inherited fragments" checkbox above is a separate, independent mechanism for the DB source only).

### User/Group TSconfig fragments

The exact same `aim.promptFragments.*` syntax also works in **User or Group TSconfig**, letting an admin assign a fragment to a specific person or role regardless of which page they're working on, a different axis entirely from page-tree tone:

```
# On a BE group or user's TSconfig field
aim.promptFragments.legal.prompt = Always include a "content may be inaccurate" disclaimer.
```

No-ops when there's no logged-in backend user (CLI, frontend); same convention as the budget/rate-limit/privacy-level TSconfig settings below.

### Code-registered fragments

For instructions that should apply everywhere regardless of which page (or no page at all) is involved (e.g. a house-brand watermark policy or a compliance disclaimer), ship:

```php
// Configuration/SystemPrompt/PromptFragments.php
return [
    ['prompt' => 'Always add a small diagonal watermark reading "DRAFT".', 'scope' => 'imageGeneration'],
    ['prompt' => 'Never use exclamation marks.'], // scope defaults to 'all'
];
```

`PromptFragmentRegistry` scans every active extension for this file and merges the results, plus a `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments']` runtime-override escape hatch. These apply on **every** request matching their scope (with or without a `pageId`) since they represent extension-level policy, not page-specific tone. The package filesystem scan is cached persistently (`aim_prompt_fragments` cache pool, flushed by "flush all caches" / extension (de)activation like any other `system`-group cache); the `$GLOBALS` override is deliberately excluded from that cache, since it's meant to be set dynamically per request/context.

### Global fallback (no page context)

Requests without a `pageId` (the default) use the `defaultSystemPrompt` Extension Configuration setting for layer 2 instead of page fragments (layers 3 and 4, user and code-registered fragments, still apply). This is deliberate, not a gap: `sys_file_metadata` (used for image alt-text) always has `pid = 0` and a file can be referenced from many pages or none, so page-tree inheritance can't be meaningfully applied to it. `descriptive_images` and similar consumers need **no code changes** to benefit from any of this: every request without an explicit `pageId` automatically gets the global default plus any user-assigned and code-registered fragments.

### Robustness notes

- **Unknown scope values normalize to `all` with a logged warning**, across every source (DB, Page/User TSconfig, code-registered): a typo (`imageGeneraton`) makes a fragment apply everywhere rather than silently never firing. Far more noticeable, and thus fixable.
- **Exact duplicate text across layers is sent only once**: e.g. if the same instruction ends up in both a DB fragment and a code-registered one, it's not sent to the provider twice.
- **Extending `SupportsSystemPromptInterface` with a new request type is safe by construction**: each implementor self-declares its own scope via `getPromptFragmentScope()` rather than being looked up from a central map; there's no separate registry that a new class could forget to update and crash on.
- **`tx_aim_prompt_fragment.prompt` has a soft (browser-enforced, HTML `maxlength`) 4000-character limit**: a fragment gets sent with every matching AI call on that page's subtree, so an oversized paste has real, ongoing cost consequences. Not a hard server-side limit (TYPO3 core never enforces `max` server-side for `type=text` fields); a raw `process_datamap` bypass could still exceed it.
- **Both new `pages` fields (`tx_aim_prompt_fragments`, `tx_aim_disable_inherited_fragments`) are `exclude => true`**: invisible to a backend user/group unless explicitly granted under "Allowed excludefields". AI tone-of-voice is a brand-consistency concern many orgs want gated rather than implied by generic "can edit this page" rights; admins always retain access regardless of group settings.

## Smart Routing

The `SmartRoutingMiddleware` classifies prompt complexity using language-agnostic structural heuristics:

- Character/sentence/line count
- Question marks, enumerations, code presence
- URLs, structural delimiters
- Multi-language keyword signals (extensible per extension)

Classification is logged per request (`complexity_score`, `complexity_label`, `complexity_reason`). When a cheaper model has proven reliable for simple prompts (based on historical request log data with minimum 10 requests and 90%+ success rate), the middleware automatically downgrades.

### Quality gate

"Reliable" on its own only means *the API call didn't error*. A cheap model can succeed every time while producing weak answers. When [LLM grading](#llm-grading) is enabled, smart routing also consults the recorded `grade_score`: a cheaper model is only chosen if its graded responses for that request type average at least **0.65** (the "good" boundary) across at least **10 graded requests**.

The gate is a one-way veto, not a tie-breaker. The cheapest cost-and-success-eligible model is still the one picked; a poor average grade simply removes a candidate. Crucially, **too few graded requests means "no signal", not "bad"**: a model with fewer than 10 graded samples is judged on cost and success rate exactly as before, so installs without grading enabled see no change in routing behavior.

The downgrade decision is logged with the candidate's graded quality, e.g. `... (avg grade: 0.82 over 14 graded)` or `... (ungraded)`.

### Extending complexity signals

Ship a `Configuration/SmartRouting/ComplexitySignals.php` in any extension:

```php
return [
    'ja' => [
        'complex' => ['比較して', '設計して', '最適化して'],
        'simple' => ['とは', 'こんにちは'],
        'multiPart' => [' と比べて'],
    ],
];
```

Or add signals at runtime:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['complexitySignals']['de']['complex'][] = 'analysiere';
```

## LLM Grading

AiM can score the quality of AI responses using a second model as a judge ("LLM-as-a-judge"). Grading is opt-in per provider configuration and runs *after* the response has been delivered to the caller, so it adds no latency to the live request.

### Enabling grading

On any provider configuration (Admin Tools > AiM > Providers), open the **LLM Grading** tab:

| Field | Purpose |
|---|---|
| `grading_enabled` | Turns grading on for this configuration |
| `judge_configuration_uid` | A *different* AiM configuration used to score responses: typically a cheaper or specialized model that supports the conversation capability |
| `grading_rubric` | The judge's instructions: what to evaluate (factual accuracy, relevance, tone, ...). The required JSON output format is appended automatically. |

Grading covers `ConversationRequest` and `TextGenerationRequest`. It only runs when the effective privacy level is `standard`, `reduced` and `none` skip it, since the judge needs the prompt and response content.

### How it runs

1. After a successful, gradeable response, `GraderMiddleware` marks the request log row `grade_status = pending` and registers a shutdown function.
2. The shutdown function runs *after* the response is flushed to the caller, then calls the judge model.
3. The judge returns a JSON `{score, label, reason}`, written back to the row (`grade_score`, `grade_label`, `grade_reason`).

If the shutdown path is missed (CLI crash, an unusual SAPI), a scheduler command picks up the stragglers:

```bash
vendor/bin/typo3 aim:grade-pending
```

Run it from the TYPO3 scheduler every few minutes. It grades rows still marked `pending` that are older than `--min-age` seconds (default 60), so it never races the live shutdown handler. The request log module shows a warning when a pending backlog builds up.

### Grades

The judge assigns one of four labels. When it returns a score but no recognizable label, the label is derived from the score:

| Label | Score range |
|---|---|
| `poor` | 0.00–0.39 |
| `fair` | 0.40–0.64 |
| `good` | 0.65–0.84 |
| `excellent` | 0.85–1.00 |

The judge call deliberately bypasses the middleware pipeline (it would otherwise produce a duplicate request-log row), but its cost is still rolled into the judge configuration's `total_cost` and recorded on the graded row's `judge_cost` column.

## Custom Middleware

Add middleware to intercept all AI requests:

```php
use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Middleware\AiMiddlewareInterface;

#[AsAiMiddleware(priority: 50)]
class MyMiddleware implements AiMiddlewareInterface
{
    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        // Before: inspect or modify request
        $response = $next->handle($request, $provider, $configuration);
        // After: inspect or modify response
        return $response;
    }
}
```

`$response` can be an unconsumed stream (`ConversationResponse`/`ToolCallingResponse` with `stream: true`); see [Streaming responses](#streaming-responses) below before reading `$response->content` or `$response->usage` in your own middleware.

### Streaming responses

Check `$response->isStreaming()` before reading `$response->content` or `$response->usage`. For a streaming response, both are still placeholders at the point any middleware sees them. The real values only exist once the caller (e.g. a controller sending SSE chunks) has fully drained `$response->streamIterator`. Reading them synchronously, as a naive logging middleware would, silently records zero tokens and zero cost instead of erroring, which makes the mistake easy to miss.

`RequestLoggingMiddleware` and `CostTrackingMiddleware` handle this by registering a `register_shutdown_function`, the same mechanism `GraderMiddleware` uses to defer grading, that reads the final numbers off the *same* `StreamChunkIterator` instance once PHP's shutdown phase runs, which in the normal request lifecycle only happens after the stream has already been fully consumed:

```php
$response = $next->handle($request, $provider, $configuration);

if (($response instanceof ConversationResponse || $response instanceof ToolCallingResponse) && $response->isStreaming()) {
    $streamIterator = $response->streamIterator;
    register_shutdown_function(function () use ($streamIterator, $configuration): void {
        $usage = $streamIterator->getUsage();                // now populated
        $content = $streamIterator->getAccumulatedContent();  // now populated
        // ...write your log/metric with the real numbers
    });
    return $response;
}

// Non-streaming: $response->content / $response->usage are already final here.
```

One caveat: if the client disconnects mid-stream, or the process is killed before PHP's shutdown phase runs, the deferred write never happens and can't be recovered afterward. The token/cost data only ever existed in that one request's memory. Unlike grading (retryable later via `aim:grade-pending`, since the underlying prompt/response is already durably stored before grading is deferred), there is currently no safety-net command for this: a crashed stream simply goes unlogged.

### Enriching the request log

Every request DTO carries a `metadata` array that lands in the `metadata` JSON column of `tx_aim_request_log`. The caller can set it upfront via `metadata`/`metadata()` on `Ai`/`AiRequestBuilder`, and any middleware can add to it later via `$request->withMetadata([...])` and forward the new instance. The original request stays immutable; downstream middlewares see the merged metadata:

```php
#[AsAiMiddleware(priority: 80)]
final class MyExtensionContextMiddleware implements AiMiddlewareInterface
{
    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $request = $request->withMetadata([
            'my_ext.additional' => 'info',
        ]);
        return $next->handle($request, $provider, $configuration);
    }
}
```

This same hook is how a *different* extension can build its own optional AiM integration entirely on its own side, without AiM needing any built-in knowledge of it: a caller opts in by setting `metadata: ['some_extension.target' => [...]]` on the request, and that extension registers its own `#[AsAiMiddleware]` reading that key back out once the response succeeds. `b13/ai-label` does exactly this (its own `FlagAiContentMiddleware`, reading a `metadata['aiLabel']` convention it documents itself), and AiM stays unaware that `ai_label` exists at all.

### Detailed / parallel logging

For richer or separate logging, register a middleware at a lower priority than `RequestLoggingMiddleware` (use a priority below `-700`). It sees the response, the resolved `$configuration`, and any metadata enriched by earlier middlewares, and is free to write wherever it likes without touching `tx_aim_request_log`.

The example below only handles the non-streaming case for brevity. See [Streaming responses](#streaming-responses) above for the `register_shutdown_function` pattern if your middleware also needs to run for `conversationStream()` or streaming tool-calling requests, where `$response->usage`/`content` aren't populated yet at this point:

```php
#[AsAiMiddleware(priority: -750)]
final class MyExtensionDetailedLogger implements AiMiddlewareInterface
{
    public function __construct(private readonly MyExtensionLogRepository $repository) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $response = $next->handle($request, $provider, $configuration);
        $this->repository->record([
            'provider' => $configuration->providerIdentifier,
            'model' => $response->usage->modelUsed,
            'metadata' => $request->metadata,
            'tokens' => $response->usage->getTotalTokens(),
            'cost' => $response->usage->cost,
            // ...any custom shape you need
        ]);
        return $response;
    }
}
```

The middleware pipeline is intentionally the only logging extension point: it gives you the request, response, configuration, and middleware context in one place, plus full control over where the data goes.

### Built-in Middleware

| Middleware | Priority | Purpose |
|---|---|---|
| `RetryWithFallbackMiddleware` | 100 | Catches errors, retries with fallback providers |
| `AccessControlMiddleware` | 90 | Provider access, capability permissions, budgets, rate limits |
| `TonePromptCompositionMiddleware` | 80 | Composes caller prompt + tone-of-voice (page/user/registry fragments); see [Tone of Voice / System Prompts](#tone-of-voice--system-prompts). Deliberately *above* SmartRouting (see next row) |
| `SmartRoutingMiddleware` | 75 | Complexity classification, cost-based model downgrade: sees the real, tone-inflated prompt, not just the caller's bare task text |
| `CapabilityValidationMiddleware` | 50 | Validates provider capability, auto-reroutes if needed |
| `ProviderAddendumMiddleware` | 10 | Appends the provider-specific addendum once $configuration is final (post-rerouting); see [Tone of Voice / System Prompts](#tone-of-voice--system-prompts) |
| `GraderMiddleware` | -600 | Schedules LLM-as-a-judge grading after a successful response |
| `RequestLoggingMiddleware` | -700 | Logs every request (respects privacy levels); defers via shutdown function for streaming responses; see [Streaming responses](#streaming-responses) |
| `CostTrackingMiddleware` | -800 | Updates cumulative cost per configuration; defers via shutdown function for streaming responses |
| `EventDispatchMiddleware` | -900 | Fires `BeforeAiRequestEvent` / `AfterAiResponseEvent`; for a streaming response, `AfterAiResponseEvent` fires with the still-unconsumed response (listeners that need final content/usage should apply the same deferred pattern) |
| `CoreDispatchMiddleware` | -1000 | Routes request to the correct provider capability method |

## Events

| Event | When | Use Case |
|---|---|---|
| `BeforeAiRequestEvent` | Before provider call | Modify request, add logging, enforce policies |
| `AfterAiResponseEvent` | After provider response | Post-processing, notifications, analytics |
| `AiRequestReroutedEvent` | When capability gate reroutes | Monitor misconfigurations, track rerouting patterns |

## Backend Modules

AiM adds an **AiM** module under Admin Tools with three sub-modules:

![AiM Backend module](Documentation/Images/module-overview-dark.png)

### Providers

Manage AI provider configurations:
- API keys, models, token costs
- Group restrictions (`be_groups`), privacy levels, rerouting protection, auto model switch
- **Available Providers**: modal with clickable model badges to enable/disable models
- **Provider verification**: test connectivity with a minimal probe request, results persisted
- **Last used**: timestamp per configuration with link to request log

![AiM Providers overview](Documentation/Images/providers-dark.png)

Click **Available Providers** to see every auto-discovered provider's models at a glance, and click any model badge to enable or disable it:

![Available Providers modal with clickable model badges](Documentation/Images/model-selection-dark.png)

### Request Log

Monitor all AI requests:
- **Statistics dashboard**: total requests, total cost, total tokens, success rate, average duration
- **Filtered log view**: filter by provider, extension, request type, success/failure
- **User tracking**: shows the backend username for each request (empty for CLI/automation)
- **Full content**: prompt, system prompt, and response content per request (respects privacy levels)
- **Complexity classification**: score, label, and reason for each request
- **Quality grades**: LLM-as-a-judge score, label, and reason per request when grading is enabled
- **Token details**: prompt, completion, cached, and reasoning token breakdowns
- **Rerouting info**: fallback and capability rerouting details

![AiM Request Log](Documentation/Images/request-log-dark.png)

Every row opens a full detail view, including the grading rationale and reroute reason when applicable:

![Request Log detail view](Documentation/Images/request-log-detail-dark.png)

### Prompt Preview

Manage and inspect [page-level tone-of-voice fragments](#tone-of-voice--system-prompts). Unlike the other two, this module is **grantable per backend user/group** (`access => 'user'`, not hardcoded admin); it only inspects a composition, never dispatches or changes anything, so the editors who actually author fragments day-to-day can preview their own work without an admin doing it on their behalf:

- **Permission-scoped list**: only pages with at least one prompt fragment, and only those the current user is actually allowed to see (own webmounts + page permissions; unrestricted for admins); a non-admin can never probe a page they don't have access to, by construction
- **Page tree in the left panel**: select a page to narrow the list to it and its subtree; a **"Create prompt"** doc-header button appears whenever a page with `PAGE_EDIT` rights is selected, opening that page's edit form (AI tab only) even if it has zero fragments yet
- **Filter by capability, free-text search** across each fragment's prompt, examples, and title
- **Per-row "Preview"**: expands inline to show the exact composition breakdown for that page: page tone, user-assigned fragments, code-registered fragments, and (optionally) a chosen provider's addendum, with a running character/token count, without triggering a real, billable AI call
- **"Edit fragments"** opens the page's normal edit form restricted to just the AI tab fields (`columnsOnly`), never the full page properties; hidden per-row when the user lacks `PAGE_EDIT` on that page
- **"Calibrate Voice"** doc-header button: see [Voice Calibration](#voice-calibration) below

![Prompt Preview module, filtered to a site's pages](Documentation/Images/prompt-preview-dark.png)

Click **"Preview"** on any row to see the exact layered composition without spending an AI call:

![Compose and inspect preview showing the layered prompt composition](Documentation/Images/prompt-preview-modal-dark.png)

## Voice Calibration

Writing a good tone-of-voice prompt fragment by hand, one that actually sounds like the site, is tedious. Voice calibration derives one from real page content instead, two ways:

### Interactively, from the Prompt Preview module

The **"Calibrate Voice"** button (always available in the [Prompt Preview](#prompt-preview) module's doc header) opens a modal where you either paste a sample of on-brand copy directly, or click **"Select page"** to pick one or more pages via TYPO3's native element browser. Picking a page:

1. Renders that page through TYPO3's real frontend rendering pipeline to extract genuine, representative copy, not just raw field values.
2. Falls back automatically to the page's stored DB fields (title, headers, bodytext) if no site/frontend can be resolved for it (e.g. no site configuration, a broken TypoScript setup), so the feature degrades gracefully instead of failing outright. The status line always shows which path was used ("rendered page" vs. "stored fields only (page render unavailable)"), so it's never a silent guess.

![Calibrate Voice modal with a rendered page inserted](Documentation/Images/calibrate-voice-dark.png)

Click **"Analyze"** and AiM derives a tone-of-voice instruction plus illustrative Q/A examples from the combined text, ready to copy into a fragment's **Prompt**/**Examples** fields.

### Automatically, for a whole site

```bash
vendor/bin/typo3 aim:calibrateVoice
```

Crawls every configured site's root page and a bounded, breadth-first slice of its subpages (root always included first, deepest/least-prominent pages dropped first if the slice needs to shrink), accumulates their real extracted content up to a size budget (stopping at whole-page boundaries rather than truncating mid-sentence, so the AI always analyzes complete, coherent pages), and derives a tone instruction + examples the same way the interactive modal does. The result is saved as an auto-calibrated `tx_aim_prompt_fragment` on the site's root page, tagged `auto_generated = 1` so re-running the command refreshes that same fragment instead of piling up duplicates, and so it never touches an editor's own hand-authored fragments.

| Option | Purpose | Default |
|---|---|---|
| `--page` | Only process this one root page uid, instead of every configured site | - |
| `--max-pages` | Maximum number of pages to crawl per site | 25 |
| `--max-depth` | Maximum tree depth below the root page to crawl (0 = root page only) | 2 |
| `--dry-run` | Calibrate and print the result without saving a fragment | - |

Schedulable as-is via TYPO3's built-in "Execute console command" Scheduler task: no dedicated Task class needed.

## Dashboard Widgets

When `typo3/cms-dashboard` is installed, AiM registers five widgets and a pre-configured dashboard preset ("AiM: AI Analytics"):

| Widget | Type | Shows |
|---|---|---|
| Recent Requests | Table | Last 10 requests with extension, model, tokens, cost, status |
| Provider Usage | Doughnut chart | Request distribution across providers |
| Model Usage | Bar chart | Request count per model |
| Success Rate | Doughnut chart | Successful vs failed requests |
| Extension Usage | Doughnut chart | Which extensions generate the most requests |

All widgets are refreshable and grouped under "AiM" in the widget picker. The recent requests widget includes a button to open the full request log module.

![AiM dashboard widgets](Documentation/Images/dashboard-light.png)

## Database Tables

| Table | Purpose |
|---|---|
| `tx_aim_configuration` | Provider configurations (TCA-managed). API keys, models, cost tracking, governance settings, per-provider system prompt addendum. |
| `tx_aim_request_log` | Per-request log (no TCA). Tokens, cost, duration, prompt/response content, complexity classification, rerouting details, LLM grading results. |
| `tx_aim_usage_budget` | Per-user budget tracking. Rolling period counters for tokens, cost, and request count. |
| `tx_aim_prompt_fragment` | Page-tree tone-of-voice fragments (TCA-managed, IRRE child of `pages`). Prompt text, few-shot examples, multi-value scope, per-fragment `inherit_to_subpages` flag, and an `auto_generated` marker for fragments written by `aim:calibrateVoice`. |

See `ext_tables.sql` for the full schema.

## Testing

```bash
cd typo3conf/ext/aim

# Unit tests
Build/Scripts/runTests.sh -s unit

# Functional tests
Build/Scripts/runTests.sh -s functional

# With specific PHP version
Build/Scripts/runTests.sh -s unit -p 8.3

# Specific test
Build/Scripts/runTests.sh -s unit -- --filter BudgetService
```

## Requirements

- TYPO3 v12.4, v13.4, or v14.0+
- PHP 8.1+
- No AI provider dependencies (bring your own via Symfony AI bridges or native implementations)

## License

GPL-2.0-or-later

## Credits

Created by [Oli Bartsch](https://github.com/o-ba) for [b13 GmbH, Stuttgart](https://b13.com).
