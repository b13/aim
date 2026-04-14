# AiM - Intelligent AI Proxy for TYPO3

AiM is the central AI layer for TYPO3. Extensions describe what they need. AiM decides which provider and model to use, routes through a middleware pipeline, and returns the result. Built for TYPO3 v12, v13, and v14.

> **New to AiM?** Read the [Introduction](Documentation/Introduction.md) for a non-technical overview of what AiM does, why it exists, and how it works for administrators and extension developers.

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
- Simple proxy API (`$ai->vision()`, `$ai->text()`, `$ai->translate()`, `$ai->embed()`)
- Fluent builder for advanced parameters
- Direct pipeline access for full control
- Structured output (JSON Schema), tool calling, streaming

**For administrators:**
- Backend modules for provider management and request monitoring
- Disable specific models per provider via clickable badges
- Budget limits and rate limiting per user (including admins as a safety net)
- Privacy levels (standard / reduced / none) per provider
- Provider group restrictions and capability permissions via native TYPO3 mechanisms

**Under the hood:**
- Zero provider dependencies. Install Symfony AI bridge packages as needed.
- Auto-discovery of installed bridges (OpenAI, Anthropic, Gemini, Mistral, Ollama, etc.)
- Capability-based routing with model-level awareness
- Auto model switch: one config covers all capabilities
- Smart routing: routes simple prompts to cheaper models based on historical cost data
- Fallback chains: automatic retry with alternative providers on failure
- 8-layer middleware pipeline: retry, access control, smart routing, capability validation, logging, cost tracking, events, dispatch

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
```

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

## Smart Routing

The `SmartRoutingMiddleware` classifies prompt complexity using language-agnostic structural heuristics:

- Character/sentence/line count
- Question marks, enumerations, code presence
- URLs, structural delimiters
- Multi-language keyword signals (extensible per extension)

Classification is logged per request (`complexity_score`, `complexity_label`, `complexity_reason`). When a cheaper model has proven reliable for simple prompts (based on historical request log data with minimum 10 requests and 90%+ success rate), the middleware automatically downgrades.

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

### Built-in Middleware

| Middleware | Priority | Purpose |
|---|---|---|
| `RetryWithFallbackMiddleware` | 100 | Catches errors, retries with fallback providers |
| `AccessControlMiddleware` | 90 | Provider access, capability permissions, budgets, rate limits |
| `SmartRoutingMiddleware` | 75 | Complexity classification, cost-based model downgrade |
| `CapabilityValidationMiddleware` | 50 | Validates provider capability, auto-reroutes if needed |
| `RequestLoggingMiddleware` | -700 | Logs every request (respects privacy levels) |
| `CostTrackingMiddleware` | -800 | Updates cumulative cost per configuration |
| `EventDispatchMiddleware` | -900 | Fires `BeforeAiRequestEvent` / `AfterAiResponseEvent` |
| `CoreDispatchMiddleware` | -1000 | Routes request to the correct provider capability method |

## Events

| Event | When | Use Case |
|---|---|---|
| `BeforeAiRequestEvent` | Before provider call | Modify request, add logging, enforce policies |
| `AfterAiResponseEvent` | After provider response | Post-processing, notifications, analytics |
| `AiRequestReroutedEvent` | When capability gate reroutes | Monitor misconfigurations, track rerouting patterns |

## Backend Modules

AiM adds an **AiM** module under Admin Tools with two sub-modules:

### Providers

Manage AI provider configurations:
- API keys, models, token costs
- Group restrictions (`be_groups`), privacy levels, rerouting protection, auto model switch
- **Available Providers**: modal with clickable model badges to enable/disable models
- **Provider verification**: test connectivity with a minimal probe request, results persisted
- **Last used**: timestamp per configuration with link to request log

### Request Log

Monitor all AI requests:
- **Statistics dashboard**: total requests, total cost, total tokens, success rate, average duration
- **Filtered log view**: filter by provider, extension, request type, success/failure
- **User tracking**: shows the backend username for each request (empty for CLI/automation)
- **Full content**: prompt, system prompt, and response content per request (respects privacy levels)
- **Complexity classification**: score, label, and reason for each request
- **Token details**: prompt, completion, cached, and reasoning token breakdowns
- **Rerouting info**: fallback and capability rerouting details

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

## Database Tables

| Table | Purpose |
|---|---|
| `tx_aim_configuration` | Provider configurations (TCA-managed). API keys, models, cost tracking, governance settings. |
| `tx_aim_request_log` | Per-request log (no TCA). Tokens, cost, duration, prompt/response content, complexity classification, rerouting details. |
| `tx_aim_usage_budget` | Per-user budget tracking. Rolling period counters for tokens, cost, and request count. |

See `ext_tables.sql` for the full schema.

## Testing

```bash
cd typo3conf/ext/aim

# Unit tests (30 tests, 54 assertions)
Build/Scripts/runTests.sh -s unit

# Functional tests (24 tests, 57 assertions)
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
