# Changelog

## 0.2.0

Quality grading, encrypted API keys, CLI testing, and smaller fixes.

- LLM grading: optional LLM-as-a-judge scoring of every response, with results stored on the request log and visible in the backend module
- `aim:grade-pending` scheduler command as a safety-net for the live shutdown-handler grading path
- Grade-aware smart routing: cheaper models are only chosen if their graded quality is good enough
- API key encryption at rest using `$TYPO3_CONF_VARS[SYS][encryptionKey]`; endpoint URLs (Ollama, LM Studio) stay plaintext
- `aim:rotateApiKeys` command to re-encrypt stored keys after a `SYS/encryptionKey` rotation
- Install Tool upgrade wizard to migrate legacy plaintext API keys
- `aim:test` CLI command for one-off requests across all capabilities; `--site` resolves the provider from a site's `settings.yaml`
- Per-request privacy level override and metadata enrichment on `AiRequestInterface`
- Live model discovery for Symfony AI bridges with dynamic catalogs (Ollama, LM Studio)
- Streaming fix: stop dropping `TextDelta` chunks from the Symfony AI bridge (#2)
- Token-limit parameter resolved dynamically per bridge (fixes Gemini and others that expect a different key)
- Backend module hidden from non-admin users (#17)
- Symfony AI bridge dependency updated; declares a conflict with `<0.8`

## 0.1.0

Initial release.

- Central AI proxy with `$ai->vision()`, `$ai->text()`, `$ai->translate()`, `$ai->conversation()`, `$ai->embed()`
- Fluent request builder and direct pipeline access (three usage tiers)
- Symfony AI auto-discovery for OpenAI, Anthropic, Gemini, Mistral, Ollama, and more
- 8-layer middleware pipeline: retry, access control, smart routing, capability validation, logging, cost tracking, events, dispatch
- Smart routing with complexity classification and cost-based model downgrade
- Auto model switch with data-driven cheapest model selection
- Governance: provider group restrictions, capability permissions, budget limits, rate limiting, privacy levels
- Backend modules for provider management and request log with statistics
- Dashboard widgets: recent requests, provider usage, model usage, success rate, extension usage
- Provider verification with persisted connection status
- Model enable/disable via Available Providers modal
- Fallback chains with automatic retry on provider failure
- Per-request logging with user tracking, token breakdowns, and rerouting details
- TYPO3 v12, v13, and v14 support
