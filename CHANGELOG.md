# Changelog

## Unreleased

- Bugfix: the Providers overview and Request Log statistics queries no longer select every column alongside their aggregates, which MySQL rejects outright under `sql_mode=ONLY_FULL_GROUP_BY` (#27)
- Bugfix: cost and score columns are declared as `decimal` instead of `double(M,D)`. Doctrine drops precision and scale for float types, so the schema comparison reported the same `ALTER TABLE` on every run without the applied statement ever changing the column, leaving "Analyze Database Structure" stuck with a change list that never went away (#27)

- Bugfix: provider API keys are encrypted in `processDatamap_preProcessFieldArray` instead of only in the post-process hook. On an update, DataHandler captures the `sys_history` diff before the post-process hook runs, so the record history kept the unencrypted value even though the `api_key` column itself was encrypted. Inserts were not affected (#30)
  
  Existing history entries are not rewritten. To drop them for a configuration whose key was changed before this fix:

  ```sql
  DELETE FROM sys_history WHERE tablename = 'tx_aim_configuration';
  ```

## 0.4.1

Bugfix release: third-party Symfony AI bridge auto-discovery, and a small footer addition.

- Bugfix: Symfony AI bridge auto-discovery now matches on the `symfony-ai-platform` Composer package type instead of the `symfony/ai-*-platform` naming convention, so third-party bridges (e.g. `mittwald/symfony-ai-platform`) are detected too, not just official `symfony/*` packages (#21, #25)
- Backend module screens now carry a small "made with ❤ by b13" signature in the footer

## 0.4.0

Site-wide tone of voice / system prompts, voice calibration, a new Prompt Management module, and a redesigned AiM-specific look across the backend.

- Page-tree prompt fragments: add named tone-of-voice instructions (plus optional few-shot examples) to any page's new **AI** tab, scoped to one or more AI capabilities, inherited additively down the page tree, with a global fallback for requests with no page context
- Fragments are reusable library entries, not page-owned: the same fragment can be assigned to any number of pages (or reused across sites), sharing its content and updating everywhere it's assigned; which pages an assignment applies to, and whether it cascades to subpages, is tracked separately per assignment
- Two more fragment sources apply regardless of page context: Page/User/Group TSconfig (`aim.promptFragments.*`) and code-registered fragments (`Configuration/SystemPrompt/PromptFragments.php`), for a person/role-level or organization-wide policy (e.g. a watermark instruction, a compliance disclaimer)
- Image generation requests get the same tone/policy layers spliced into the prompt itself, since image APIs have no system-role channel
- New **Prompt Management** backend module, grantable per user/group rather than admin-only (module access plus `tables_select`/`tables_modify` on `tx_aim_prompt_fragment` control what a granted user can see/edit): two sub-actions switched via a pair of clickable boxes, sharing one doc header/breadcrumb/page tree - **Pages** (a permission-scoped, searchable list of pages with configured fragments, page-tree integration, and a per-row "compose & inspect" preview that shows the exact layered composition for a page without spending an AI call) and **Library** (browse/search/reuse every fragment independent of any one page, one row per fragment title, with a "used on N page(s)" disclosure per fragment; also honors the shared page tree, scoping to fragments actually used in the selected subtree, while global fragments stay exempt and are marked with a "Global" badge whenever a page is selected)
- **Voice calibration**: derive a tone-of-voice fragment from real page content instead of writing one by hand: interactively via the "Calibrate Voice" button (paste sample copy, or pick pages through the element browser, with real frontend-rendered content extraction and a safe fallback to stored fields), or for a whole site at once via the new `aim:calibrateVoice` CLI command, which crawls a bounded, representative slice of a site's pages and saves the result as a fragment on its root page (schedulable, safe to re-run)
- `disableSystemPromptComposition` and `systemPromptOverride` request options let a caller opt out of automatic composition entirely, or override the tone/policy layers for one specific call
- Security hardening: once a provider API key is encrypted, it is never decrypted for display again anywhere in the backend: the edit form masks it behind a password input instead, and the Providers overview / connection-verification response never expose it either
- Security hardening: every AJAX endpoint now enforces the same permission its parent backend module would (admin-only for Providers/Request Log actions, module/table grants for Prompt Management/Calibrate Voice), since AJAX routes are never covered by TYPO3's own module-access check
- Redesigned AiM's own screens with a distinctive "mixing console" visual identity: composed prompt layers render as colored channel strips feeding a "master out" readout, model/provider status reads as glowing LED indicators instead of plain badges, and request log statistics read as a meter bank rather than plain stat cards. Applied across the Providers overview, Available Providers modal, Request Log (list, statistics, detail), Prompt Management, and the Calibrate Voice modal, with full light/dark theme support; standard TYPO3 chrome (doc header, module navigation, forms) is left untouched, so only AiM's own content areas carry the distinctive look
- `Ai`/`AiRequestBuilder` gain a `metadata`/`metadata()` option so a caller can attach custom context to a request upfront, not just from a middleware via `withMetadata()`. This is the hook `EXT:ai_label`'s own optional middleware uses (tagging `metadata['aiLabel']` to flag a record as AI-created/AI-modified once the response succeeds) without AiM needing any built-in knowledge of ai_label

## 0.3.0

Tool calling improvements, image generation, request log detail view, and sortable listings.

- Request log detail view: every request now has a stable, linkable detail page (`aim_request_log.show`) showing the full untruncated prompt/response and all fields, reachable from the list's timestamp or a dedicated details button, so other extensions logging through AiM can link straight to a specific request instead of pointing at the list
- Image generation support via `$ai->generateImage()`, optionally guided by a reference image, through the same proxy API as every other capability
- Streaming support for tool-calling requests: text deltas and tool-call deltas are both exposed instead of dropping the latter, with an optional callback fired as soon as a tool call starts
- Sortable columns in the request log and providers listing
- Bugfix: tool schema is now serialised per provider through Symfony AI's native normalizers instead of a single hardcoded shape, fixing 400s on the OpenAI Responses API and incorrect shapes on Anthropic/Gemini
- Bugfix: tool call/result round-tripping now uses Symfony AI's native message types, so providers see their actual protocol (tool_use/tool_result on Anthropic, function_call/function_call_output on the OpenAI Responses API, functionCall/functionResponse on Gemini) instead of losing the tool exchange on the next turn
- Bugfix: streaming requests now go through the same logging/cost-tracking governance as non-streaming ones, reading real usage and content off the stream once it's drained instead of logging placeholder zeros
- Bugfix: tool-calling requests are graded once they produce a final text answer; intermediate turns still awaiting tool execution are correctly skipped
- If used, `symfony/ai-platform` now needs to be ^0.9 (tested up to 0.11), up from ^0.8; it remains an optional suggestion, not a hard dependency

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
