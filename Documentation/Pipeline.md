# The request pipeline

Smart routing, quality grading, and how to add middleware of your own to the eleven stages every request passes through.

[Back to the README](../README.md)

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
| `RetryWithFallbackMiddleware` | 100 | Catches errors and empty responses, retries against the configurations that accept rerouted requests |
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
