# Tone of voice and system prompts

How the final prompt is composed from up to five layers, how page-tree fragments are assigned and inherited, and how to derive a tone of voice from a site.

[Back to the README](../README.md)

## How the prompt is composed

Two middlewares automatically compose the final prompt sent to the provider, from up to five layers, in this order:

1. **The caller's own prompt**: the domain-specific instruction a consuming extension already sets (e.g. `descriptive_images`' "generate alt text...", or the creative prompt for image generation). Unchanged.
2. **The resolved tone of voice**: page-tree prompt fragments, DB and Page-TSconfig sourced (see below), or the global fallback when there's no page context.
3. **User/Group-TSconfig-assigned fragments**: see below. Apply whenever a backend user is present, regardless of page context.
4. **Code-registered fragments**: see below. Apply regardless of page context.
5. **The provider-specific addendum**: `system_prompt_addition` on the `tx_aim_configuration` row actually used (after any rerouting/fallback), for provider-specific quirks or instructions.

Layers 1-4 are composed by `TonePromptCompositionMiddleware` (priority 80); layer 5 by a separate `ProviderAddendumMiddleware` (priority 10). They're split across two different points in the pipeline on purpose:

- The addendum is tied to a specific provider configuration, so it must run after `CapabilityValidationMiddleware`/`SmartRoutingMiddleware` have settled on the *final* one (rerouting/downgrade already happened); same reasoning the original single combined middleware had.
- Tone/user/registry fragments don't depend on the provider at all, so `TonePromptCompositionMiddleware` runs *before* `SmartRoutingMiddleware` instead. This matters: `SmartRoutingMiddleware`'s complexity classification only ever looks at the caller's bare task prompt. If tone composition ran later (as it originally did, in one combined middleware at priority 10), a short/simple task prompt could get waved through to a cheaper/weaker model even though the actual outbound payload (once tone-of-voice fragments were added) was large and instruction-heavy. Running tone composition first means smart routing sees the real prompt.

A layer that cannot be read at all is a different matter from an empty one. The page tone comes from the database, so a deployment whose schema update has not run yet makes reading it fail; the request then goes out without that layer and the reason is logged, rather than failing outright over an addition to the prompt. The prompt preview in the Prompt Management module makes the opposite choice and marks such a layer explicitly, because a diagnostic view that quietly omits a layer reads as "nothing is configured here".

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

Fragments live in a reusable library (`tx_aim_prompt_fragment`), separate from where they're used. A fragment's instruction text exists once and can be assigned to any number of pages (e.g. the same tone-of-voice fragment reused across a multi-site install's country subsites), rather than being copy-pasted onto each one. It usually lives at `pid=0`; an editor without access to `pid=0` can instead place it in a sysfolder within their own site, since the assignment picker only offers fragments stored globally or within the page's own site tree.

Every page has a repeatable **AI** tab (`tx_aim_prompt_fragments`, an inline/IRRE field) listing that page's **assignments**, where you pick an existing library fragment or create a new one inline. Each assignment has:

- **Fragment**: the library entry to use, its Prompt (the actual instruction text), optional Examples (few-shot text, appended after the prompt as `"\n\nExamples:\n" . examples` whenever included, since pairing an instruction with concrete example text steers output more reliably than adjective-laden prose alone), and Scope (one or more capabilities it applies to, via checkboxes: `Text Generation`, `Vision`, `Translation`, `Conversation`, `Tool Calling`, `Image Generation`, all six checked by default on a new fragment; a fragment matches a request if any of its checked capabilities matches the request's own).
- **Inherit to subpages** (default on): when enabled, this assignment also applies to every page below this one, in addition to this page itself.

Inheritance is **additive per assignment**, not a single overridable value: a page's own assignments always apply to itself; an inheriting ancestor's assignments are added on top. A subpage adding its own assignment *supplements* what it inherited; it never silently drops an ancestor's assignment. Composition order is root-to-target, so general tone reads before page-specific instructions.

A page can also check **"Disable inherited prompt fragments"** to skip every ancestor assignment for that page specifically (its own assignments still apply), useful for a microsite/campaign section that must not pick up the corporate tone. This is page-local, not a subtree boundary: the page's own children are unaffected and keep inheriting from the original ancestors normally.

DB fragment inheritance stops at the nearest `is_siteroot` ancestor: in a nested-site install (one site's page tree living under another site's), a page's own assignments never leak into an unrelated site. Page TSconfig fragments deliberately don't get this treatment; `getPagesTSconfig()` has never respected site boundaries, and that's an established, technical-audience convention this extension doesn't override.

DB fragments respect workspace overlays for edits to *either* the assignment or the library fragment's own content: an edit made in a workspace is visible when resolving within that workspace. A fragment or assignment created entirely new within a workspace (no live counterpart yet) won't appear until published.

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

### Reading and writing fragments from another extension

`B13\Aim\Service\PromptManagementApi` is the public, injectable way for another extension to show or maintain this data without reaching into AiM's tables, repository or route identifiers. It is deliberately narrow: displaying and maintaining assignments from outside, not the Prompt Management module's own listing, pagination and usage enrichment.

| Method | Returns |
|---|---|
| `findFragmentsForPage(int $pageId, string $returnUrl)` | `list<PageFragmentAssignmentInfo>`: the fragments assigned **directly on that page**, not resolved across the rootline. Each carries its title, resolved scope labels, `inheritToSubpages`, an `editUrl` that returns where you say, and `isActive`. |
| `findToneOfVoice(int $rootPageId, string $returnUrl)` | `ToneOfVoiceInfo` or `null`: the one auto-detected tone-of-voice fragment for a site root, with its text, examples, `isCliCalibrated`, an `editUrl` and `isActive`. |
| `countAllFragmentAssignments()` | `FragmentAssignmentCounts` (`total`, `inherited`), for a summary panel with no page selected. |
| `saveAutoDetectedFragment(...)` | The assignment uid. Upserts, so a second call refreshes the existing fragment instead of creating a duplicate. |
| `buildPromptManagementUrl(array $parameters = [])` | A URL into the Prompt Management module. |

`isActive` on both info objects is the thing to render: an inactive assignment exists but is never composed into a prompt, and a UI that does not show the difference will present a tone of voice as live when it is not.

`saveAutoDetectedFragment()` writes **inactive by default** (`$hidden = true`). What it saves is machine-derived, usually from page copy nobody has vetted, and it would otherwise apply to every AI request in that tree the moment it is written. Pass `$hidden = false` only where a human has already approved the text. A re-run never re-hides an assignment somebody activated. `$source` identifies the calling feature and drives `findToneOfVoice()`'s `isCliCalibrated`; it is not derived from `$title`, which an editor stays free to rename.

### Robustness notes

- **Unknown scope values normalize to `all` with a logged warning**, across every source (DB, Page/User TSconfig, code-registered): a typo (`imageGeneraton`) makes a fragment apply everywhere rather than silently never firing. Far more noticeable, and thus fixable.
- **Exact duplicate text across layers is sent only once**: e.g. if the same instruction ends up in both a DB fragment and a code-registered one, it's not sent to the provider twice.
- **Extending `SupportsSystemPromptInterface` with a new request type is safe by construction**: each implementor self-declares its own scope via `getPromptFragmentScope()` rather than being looked up from a central map; there's no separate registry that a new class could forget to update and crash on.
- **`tx_aim_prompt_fragment.prompt` has a soft (browser-enforced, HTML `maxlength`) 4000-character limit**: a fragment gets sent with every matching AI call on every page it's assigned to, so an oversized paste has real, ongoing cost consequences. Not a hard server-side limit (TYPO3 core never enforces `max` server-side for `type=text` fields); a raw `process_datamap` bypass could still exceed it.
- **Both new `pages` fields (`tx_aim_prompt_fragments`, `tx_aim_disable_inherited_fragments`) are `exclude => true`**: invisible to a backend user/group unless explicitly granted under "Allowed excludefields". AI tone-of-voice is a brand-consistency concern many orgs want gated rather than implied by generic "can edit this page" rights; admins always retain access regardless of group settings.

## Voice Calibration

Writing a good tone-of-voice prompt fragment by hand, one that actually sounds like the site, is tedious. Voice calibration derives one from real page content instead, two ways:

### Interactively, from the Prompt Management module

The **"Calibrate Voice"** button (always available in the [Prompt Management](#prompt-management) module's doc header) opens a modal where you either paste a sample of on-brand copy directly, or click **"Select page"** to pick one or more pages via TYPO3's native element browser. Picking a page:

1. Renders that page through TYPO3's real frontend rendering pipeline to extract genuine, representative copy, not just raw field values.
2. Falls back automatically to the page's stored DB fields (title, headers, bodytext) if no site/frontend can be resolved for it (e.g. no site configuration, a broken TypoScript setup), so the feature degrades gracefully instead of failing outright. The status line always shows which path was used ("rendered page" vs. "stored fields only (page render unavailable)"), so it's never a silent guess.

![Calibrate Voice modal with a rendered page inserted](Images/calibrate-voice-dark.png)

Click **"Analyze"** and AiM derives a tone-of-voice instruction plus illustrative Q/A examples from the combined text, ready to copy into a fragment's **Prompt**/**Examples** fields.

### Automatically, for a whole site

```bash
vendor/bin/typo3 aim:calibrateVoice
```

Crawls every configured site's root page and a bounded, breadth-first slice of its subpages (restricted to pages a visitor could see: hidden and time-restricted pages, sysfolders, recyclers and pages behind an `fe_group` are skipped, and this applies to the page you point it at as much as to its descendants, so `--page` on a hidden page or a storage folder crawls nothing; deepest/least-prominent pages are dropped first if the slice needs to shrink), accumulates their real extracted content up to a size budget (stopping at whole-page boundaries rather than truncating mid-sentence, so the AI always analyzes complete, coherent pages), and derives a tone instruction + examples the same way the interactive modal does. The result is saved as an auto-calibrated `tx_aim_prompt_fragment` (stored at the site's root page) with a matching assignment on that same root page, tagged `auto_generated = 1` on the assignment so re-running the command refreshes that same fragment instead of piling up duplicates, and so it never touches an editor's own hand-authored fragments.

**A newly saved assignment is inactive.** Its text is derived from crawled page content, and an active fragment is composed into the system prompt of every subsequent AI request in that page tree, so it waits in the [Prompt Management](#prompt-management) module until a human reads it and switches it on. It is listed under **Pages** for the page it belongs to, and under **Library** in that fragment's "used on N page(s)" disclosure; either row's edit action opens the page's AI tab, where the assignment's **Active** toggle is. Activating one is a decision the next run does not reverse: a plain scheduled run refreshes the content and never switches an active assignment back off. Pass `--activate` to save straight to active where that review is not wanted, on a first run or a later one.

| Option | Purpose | Default |
|---|---|---|
| `--page` | Only process this one root page uid, instead of every configured site | - |
| `--max-pages` | Maximum number of pages to crawl per site | 25 |
| `--max-depth` | Maximum tree depth below the root page to crawl (0 = root page only) | 2 |
| `--dry-run` | Calibrate and print the result without saving a fragment | - |
| `--activate` | Save the fragment as active instead of inactive, skipping human review | - |
| `--scope` | Comma-separated capabilities the fragment applies to (`text`, `vision`, `translation`, `conversation`, `toolCalling`, `imageGeneration`) | all |
| `--no-inherit` | Apply the fragment to the root page only, instead of inheriting it to every subpage | - |

Schedulable as-is via TYPO3's built-in "Execute console command" Scheduler task: no dedicated Task class needed.
