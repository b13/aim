# Changelog

## Unreleased

- **Feature "Routing latency"**: A cheaper model is only chosen if it is also fast enough. Smart
routing already read each model's average duration from the request log and then ignored it; a
candidate that takes more than twice as long as the current model is now skipped, because waiting is
a cost too. As with the quality gate, no recorded duration means no veto.


- **Bugfix "Downgrade visibility"**: A smart-routing downgrade is recorded in the request log as a
reroute. It was written only to the system log, so the Request Log showed an ordinary request against
the cheaper model, with no reroute flag and the substituted model in the requested-model column. The
row now carries `reroute_type = model_switch`, keeps the model the caller actually asked for, and
states what the decision was made on: both costs, both durations, the average token count and the
grade.

## 0.5.0

**Security** and governance release. Requests reach the provider they were sent to, a pinned configuration stays pinned,
endpoint URL and credential are separate fields, and auto-generated prompt instructions wait for a human.

### Important Upgrade Notes

#### 1. **Run the database schema update.**

Three new columns: `tx_aim_configuration.endpoint`, `tx_aim_configuration.accepts_rerouted_requests`,
`tx_aim_page_prompt_fragment.hidden`.

Three new cache tables: `cache_aim_prompt_fragments`, `cache_aim_ratelimit`, `cache_aim_models`.

What happens in the window between deploying the code and running the update, measured rather than reasoned about: an
AI request is still answered, without its tone of voice, and the reason is logged. What does not work until the update
runs is writing. Saving a provider configuration in the backend fails, because the new columns are not there yet, and so
does `aim:calibrateVoice`, and so does a consuming extension that reads a page's fragments. In the Providers module,
sorting by the new Endpoint column fails, while the listing itself and picking a provider for a request are unaffected.
Deploy and update in one step and none of this applies.

#### 2. **Run both upgrade wizards.**

Execute in the Install Tool or with `typo3 upgrade:run`:
* **[AiM] Split endpoint URLs out of the API key column**
* **[AiM] Preserve rerouting restrictions after the flag split**

The order does not matter, both report themselves as pending until the schema is in place and the migration has actually
run. The second one matters even if you never touched the new setting: `accepts_rerouted_requests` defaults to on, so
until it runs, a configuration you had pinned accepts other configurations' traffic. With the schema updated and the
wizards not yet run, everything else keeps working, with one exception worth knowing: for a configuration whose endpoint
URL carries an inline credential, the Model dropdown stays empty until the wizard has split it.

#### 3. **Execute manual history cleanup.**

If a provider API key was ever changed on an earlier version, that key is still readable in the record history.
Nothing rewrites those rows, so purge them once:

   ```sql
   DELETE FROM sys_history WHERE tablename = 'tx_aim_configuration';
   ```

#### 4. **Re-pick the provider where a third-party bridge is configured.**

Only relevant if you installed a provider bridge published by someone other than Symfony and configured it between 0.4.0
and 0.5.0. Such a bridge now gets a usable name, so the value stored in those configurations no longer matches. The
provider select shows it as an invalid value and requests through them are skipped. Open each affected configuration,
pick the provider again and save. (#32).

#### 5. **Expect provider traffic to shift.**

If a consuming extension names a provider explicitly, those calls used to land on the default configuration and
now reach the one they asked for.

<hr/>

### TL;DR

The four changes most likely to matter to you:

* **A request reaches the provider it named.** A call that asked for a specific configuration used to land on the
default one. If a consuming extension names providers explicitly, its traffic moves.

* **Rerouting is two settings instead of one.** A configuration can now be kept out of other configurations' traffic
while still being allowed to reroute its own, and the setting is honoured on every path that can pick a provider the
caller did not name.

* **Endpoint URL and credential are separate fields, and the credential is always encrypted.** It is also kept out of
error messages, out of the record history and out of the edit form. Two upgrade wizards move existing configurations
across.

* **A tone of voice written by the AI itself waits for a human.** Automatic calibration saves its result inactive, so
nothing reaches a live request before someone has read it.

Beyond that: the Model dropdown now works with hosts that require a credential and remembers what they answered, a
stored API key can be removed, _reduced_ privacy and "log nothing" also hold for a rerouted request, the backend
listings can be used without a mouse and without sight, and TYPO3 12.4 is genuinely supported.

<hr/>

### Features & Bugfixes in detail


#### Providers and credentials

- **Feature "Endpoint and credential"**: The Endpoint URL and credential are separate fields now. The endpoint is stored
as plain text, the credential is always encrypted, and a bridge that declares both parameters is handed both, which is
what a self-hosted gateway needs. The Providers overview gains a sortable **Endpoint** column, showing _hosted_ where a
provider has none of its own. Two things keep the transition safe: everything that reads an endpoint falls back to the
old column, so a configuration the upgrade wizard has not reached yet still resolves its models, and a save that says
nothing about the endpoint leaves that value alone, so editing an unrelated field cannot drop a configuration's only
endpoint. A credential embedded in such a URL is never rendered, neither in the overview nor in the form.


- **Documentation Provider setup**: The README explains how to fill in a provider configuration, with a table of worked
examples for hosted OpenAI and Anthropic, Ollama locally and in Docker, Ollama behind a token-checking proxy, and an
OpenAI-compatible gateway. It states which bridge accepts which of the two fields, that the model list is fetched from
`{Endpoint URL}/v1/models` so the endpoint has to be the base that path hangs off, and the save-then-reload order a
credential-protected host needs. It also states where a bridge's identifier comes from, since that is what an integrator
passes to `->provider()` and what the provider select stores, and that a bridge without a `ModelCatalog` class is not
treated as a bridge at all.


- **Feature "Credentials in URLs"**: A credential typed into the endpoint URL is stored apart from it and put back only
for the request. Some hosts only do basic auth, where the credential belongs in the URL and a bearer token is rejected,
so `https://user:token@host` keeps working: saving moves the password half into the encrypted key field and leaves
`https://user@host` as the endpoint, and every outbound request reassembles the full URL in memory, sending the
credential either in the URL or as a header but never both. Filling in the key field explicitly wins over a credential
in the URL. The upgrade wizard splits legacy rows through the same code, so a URL you type today and one migrated from
an earlier version end up stored identically. Site settings never pass through the backend's save path and are split the
same way, so a password left in `ai.endpoint` is not sent alongside `ai.apiKey`. Where both are set `ai.apiKey` wins and
the conflict is logged as a warning, there being no form to show it in.


- **Feature "Model discovery"**: The Model dropdown also fills for a host that requires a credential. When you open a
provider configuration, AiM asks the host which models it offers, and that question carries the configured credential as
a bearer token, so a self-hosted host that protects its model list (Open WebUI, a gated vLLM or LM Studio) can answer
it. The credential is used for that one request only, on the server. It is never rendered into the form, the dropdown or
the page source, and it is not sent when the stored credential _is_ the endpoint URL, which is what a pre-0.5.0 record
looks like. Together with the field split, this is what makes such a provider configurable at all.


- **Feature "Model list cache"**: Model lists are remembered, so opening a provider configuration no longer waits for
the host every time. A host that answered is remembered for 15 minutes. One that is unreachable, refuses or has nothing
to offer is remembered for 60 seconds only, because that is the case that costs a full connection timeout on every form
render, and because a fixed endpoint has to start working again quickly. The list is deduplicated, capped in length, and
looked up per endpoint and credential, so a trailing slash does not create a second entry that can go stale, and
rotating the credential does not serve you the old list. Flushing the system caches picks up a model added on the server
without waiting.


- **Feature "Optional model"**: The Model field may stay empty while you set a provider up, and a configuration without
a model is treated as disabled. A host that only reveals its model list to an authenticated request cannot be asked
before the record exists, so save the provider, endpoint and credential first and pick the model on the next load. Until
then the configuration is skipped by everything that looks for a provider, exactly as a disabled one is, and the
Providers overview marks it _no model_ rather than showing an empty cell. The enable and disable toggle keeps showing
what you actually set.


- **Feature "Upgrade wizards"**: Both upgrade wizards can be run in any order and more than once. Reached before the
database schema update, they report themselves as pending, rather than aborting the Upgrade Wizard panel, `upgrade:run`
and the Reports module, and rather than marking themselves done without having migrated anything. The endpoint wizard
reports every configuration that still holds a readable credential, including one where an endpoint has since been
filled in by hand, and leaves such a hand-set endpoint alone instead of overwriting it with the old host. The rerouting
wizard records that it ran, so a second run cannot undo a _pinned_ configuration that you deliberately left accepting
rerouted requests.


- **Bugfix "Bridge names"**: A provider bridge published by anyone other than Symfony gets a usable name. Bridges are
discovered by their Composer package type, so a third-party one is found as readily as Symfony's own. Where the package
name cannot be interpreted, AiM uses the name the bridge states for itself, which it reads while the container is built,
without a credential and without contacting anything: `t3ppy/symfony-ai-platform` gives `t3ppy`, shown as _Symfony AI:
T3ppy_ instead of _Symfony AI: T3ppy/symfony Ai_. A bridge that states no name, or states something unusable, keeps the
name derived from its package, and Symfony's own packages keep theirs unchanged. For a third-party bridge the name does
change, so a configuration created for one since 0.4.0 needs its provider picked once, which stores the new name. Until
then the select shows the old value as invalid and requests through that configuration are skipped, see Upgrade note 4
(#32, reported by @martin-helmich).


- **Documentation "Site settings"**: The README documents configuring a provider through a site's settings. All four
keys are described, `endpoint` included, which is new in this release. It also says plainly that these values are not
encrypted, because a site settings file is configuration and usually version-controlled, which is the one thing a reader
coming from the database would assume otherwise.


- **Feature "API Key removal"**: A stored API key can be removed. A stored key is never shown again, so an empty field
means "keep it", and the button next to the field is how you say "remove it": it marks the key for removal on the next
save. Nothing happens until you save, so closing the form takes it back, and so does clicking the button again or typing
a replacement key. While it is armed, the field says _will be removed on save_. It is offered only where there is a key
to remove, so a new record does not promise to delete one that does not exist.


- **Feature**: Saving tells you when the endpoint changed and the stored key was kept. That combination means the old
credential is about to be sent to a different host, and nothing can tell whether that was intended, so it reports rather
than decides.


- **Feature "Discarded password"**: Saving a provider configuration also tells you when a password had to be discarded.
Filling in both the password inside the endpoint URL and the API Key field means one of the two is dropped, and the key
field is winning.


- **Documentation "Basic auth"**: The Endpoint URL and API Key field descriptions say what basic auth needs. The user
name has to stay in the endpoint URL, because that is what marks the credential as belonging in the URL. With only a
password in the key field and no user name in the URL, the value goes out as a bearer token instead. The README also
spells out how to change such a password later, either through the key field or by entering the endpoint with the new
password again, and warns that a password manager autofilling the key field makes that field win, which looks exactly
like the change not taking effect.


- **Documentation "Field descriptions"**: The provider configuration fields that cannot be guessed now carry a
description.


- **Bugfix "Error messages"**: A provider's error message can **no longer carry your credential**. They are now cleaned
before they leave: the configured key in every encoding it can arrive in, the common vendor key shapes, `Bearer`
headers, credentials inside a URL and keys passed as a query parameter. The host stays readable, so the message still
tells you what went wrong. This also covers the moment the bridge is handed the credential in order to be built at all,
which is where a bridge that quotes the key while rejecting it would otherwise have put it straight into the log. A user
name in a URL is removed too, even without a password next to it: a user name alone is an identifier and not a secret,
but some providers use the key itself as the user name, and nothing in the text tells the two apart.


- **Bugfix "Record history"**: Changing an API key no longer leaves the old one readable in the record history. The
value is encrypted before TYPO3 records the change, so the history entry holds ciphertext like the column itself. Newly
created configurations were never affected, and existing history entries are not rewritten (#30). See Upgrade note 3 for
the statement that drops them.


- **Bugfix**: The API Key field is a password field in its own right, not just masked at runtime, so a key left readable
by an install that never ran the encryption wizard no longer appears in a plain text input.


- **Bugfix "Unreadable keys"**: A value that only looks encrypted, and one that can no longer be decrypted, no longer
break the module. Something that merely starts with AiM's `aim:enc:` marker is recognised as plain text and encrypted
properly, instead of being stored as if it were ciphertext and then failing on every read of that configuration. And
where the system encryption key was rotated without running `aim:rotateApiKeys`, the stored credential is treated as
unset, so the Providers module still opens and the configuration can be fixed there rather than in the database.


- **Bugfix**: `aim:rotateApiKeys` takes the previous encryption key from the `AIM_OLD_ENCRYPTION_KEY` environment
variable or from a hidden prompt. Passing it as `--old-key` still works but warns, because that puts the system
encryption key into the process list and the shell history.


- **Bugfix**: The provider dropdown in the Prompt Management module is built from just the four columns it displays,
instead of loading whole configuration rows, credential included, into a view a non-admin editor can open.


#### Where a request goes

- **Feature "Rerouting directions"**: The two rerouting directions are separate settings now. _Allow rerouting_ means
only "this configuration's own requests may go elsewhere". The new _Accept rerouted requests_, on by default, controls
whether other configurations may hand their traffic to this one, as a fallback or as a cheaper alternative. The two
combine freely, so a local model pinned for confidential work can still absorb an outage of the cloud default: keep my
traffic here, but do take overflow. The upgrade wizard **[AiM] Preserve rerouting restrictions after the flag split**
switches the new setting off wherever rerouting was already switched off, so existing installs keep behaving exactly as
they do today.


- **Bugfix "Explicit providers"**: A request goes to the provider it was sent to. Naming one explicitly, whether as
`->provider('ollama:llama3')`, as a configuration id or through site settings, now decides where the request lands,
instead of being resolved correctly and then rewritten to the capability's default configuration. **This changes where
requests go on existing installs**: a caller that names a provider will now reach it, which can shift cost and load
between configurations.


- **Bugfix "Rerouting coverage"**: A pinned configuration stays pinned on every route, not only when a cheaper model
is picked. Four routes can move a request to a configuration the caller never named, and all four now check the user's
group access, keep the strictest privacy level involved, and honour both rerouting settings:
  * a retry, after the chosen provider failed or answered nothing. This one is worth knowing about: the retry resends
    the identical payload, extracted page content and images included, so a pinned configuration that timed out no
    longer hands its material to a configuration the current user is not even allowed to open.
  * a switch, because the chosen provider cannot do what was asked of it, vision for example.
  * reusing a configuration's credential to run a different model than the one it has.
  * naming a model no configuration has, where AiM assembles a temporary configuration around a borrowed credential
    and endpoint. It now inherits the restrictions of the configuration it borrowed from, instead of starting out with
    permissive defaults, so a configuration set to log nothing keeps its privacy level. Reachable from PHP only, never
    from a form.


- **Bugfix "Limits per request"**: Budget and rate limit are applied once per request, and a refusal ends the request.
The check runs before the fallback chain instead of once per provider in it, so an editor gets the limit that is
configured, rather than that number divided by the length of the chain. A refusal is also told apart from a provider
being down: it stops the chain instead of sweeping it, spends one slot, and is reported against the configuration that
was actually refused.


- **Bugfix "Tool calls"**: A tool call naming a tool the request never offered is dropped. AiM does not execute tools,
it hands them back for the calling extension to run, and that extension had no way to tell a legitimate call from one a
manipulated model invented. Dropping is logged, so a bridge that reports names in an unexpected shape is visible.


- **Bugfix**: A fallback chain belongs to the one request it was built for. It is built per request and cleared with it,
so a later request cannot inherit the previous one's chain and be retried against providers nobody asked for, each
attempt with its own log entry and rate limit slot.


#### Governance and privacy

- **Bugfix "Privacy and rerouting"**: A configuration set to log nothing keeps that promise when its request is rerouted.
The strictest privacy level involved in a request travels with it, the way a per-request override already did, so a
fallback, a cheaper model or a missing capability cannot move a request to a laxer configuration and have the full
prompt and response written to the request log.


- **Bugfix "Reduced privacy"**: Privacy level _reduced_ also withholds the provider's own error text, the reason a request
was rerouted and the raw usage payload. A provider that refuses a prompt routinely quotes it back, so those three carry
prompt content exactly when the prompt is most likely to be the sensitive kind. Prompt, instructions, response and
metadata were already blanked. That a request failed is still recorded.


- **Bugfix "Capability permissions"**: A permission option belonging to another extension no longer switches AiM's
capability restrictions on. The check matches AiM's own options exactly, rather than looking for `aim:` anywhere in the
user's permission options, which any option ending in "aim" satisfied, `claim` and `reclaim` among them. Such a user was
then refused every capability while having been granted none.


- **Bugfix "Command line access"**: A command can use a configuration restricted to a backend group again. TYPO3 hands
every command line run a placeholder backend user, and AiM recognises that user by its class: group restrictions no
longer exclude restricted configurations from fallback chains or from model switching, and the rate limit does not
apply, which is what the README already stated for Scheduler tasks run from the command line.


- **Bugfix "Rate limit default"**: A default rate limit of 60 requests per minute per backend user applies when no
`aim.rateLimit` TSconfig is set, where a default install capped nothing at all. Set `aim.rateLimit.requestsPerMinute =
0` to switch it off. Command line runs stay exempt, decided by what kind of user is running and not by its name. The
two expensive AJAX endpoints are capped as well: 12,000 characters for voice calibration and 25 pages for content
extraction, where each page costs a full frontend render.


- **Bugfix "Rate limit counting"**: The rate limit counts requests in a counter of its own instead of counting rows in the
request log. A configuration set to log nothing writes no rows, so those were exactly the configurations the limit never
applied to. If the counter cannot be written, that costs the limit and never the request.


- **Bugfix "Preview permissions"**: The prompt preview requires access to the Prompt Management module, not merely read
access to the fragment table, and it answers 403 when refused instead of 200 with an error in the body. The preview
composes what a provider would receive, which includes that provider configuration's own prompt addition, written by an
administrator on a table editors cannot open. It is now composed only for a configuration the user may actually use, so
walking through configuration ids no longer hands out each one's text.


- **Bugfix**: The request log attributes a request to the backend user who made it. A user id passed in by the calling
code used to win, which both misattributed the entry and evaded the per-minute limit; that value is now kept as metadata
instead.


- **Bugfix**: The cost and score columns no longer make "Analyze Database Structure" offer the same change forever.
Declared as floating point, the comparison lost their precision and scale and kept proposing an `ALTER TABLE` that
never  actually changed anything, so the list of pending changes never went away (#27).


- **Bugfix**: The return link in the Request Log detail view only accepts a local URL. The value was escaped but its
scheme was never checked, so a `javascript:` URI survived into the link.


- **Bugfix**: A request log entry that cannot be written no longer writes the whole row, prompt, composed instructions
and response included, into the system log. The failure itself is still reported.


- **Bugfix**: Metadata passed in by the calling code no longer destroys a response that was already paid for. Encoding
it happened outside the surrounding error handling, so one invalid character threw the answer away after the provider
had been billed. Invalid characters are now substituted.


#### Prompts and tone of voice

- **Bugfix "Preview accuracy"**: The prompt preview says when it could not read a layer, instead of showing it as empty.
The two look identical in a preview and mean opposite things: a diagnostic view that quietly leaves out the tone of
voice reads as "none is configured". Such a layer is now marked as unavailable and the reason is logged.


- **Bugfix**: A tone of voice that cannot be read no longer takes the AI request down with it. The realistic cause is a
deployment whose database schema update has not run yet. The request now continues without the page's tone and the
reason is logged, rather than being answered with a database error.


- **Feature "Prompt management API"**: What a consuming extension reads back from `PromptManagementApi` says whether an
assignment is active. Both info objects carry an `isActive` flag, so a consumer's own interface can tell a fragment that
is waiting for a human from a live one, rather than presenting a parked fragment exactly like a working one. The flag
defaults to true, so existing code keeps working. Note that `saveAutoDetectedFragment()` saves inactive by default,
which is the right gate for text a machine derived but not for a document a person deliberately uploaded, so pass
`hidden: false` for that case.


- **Bugfix "Automatic tone of voice"**: A tone of voice derived automatically is saved inactive and waits for a human.
`aim:calibrateVoice` builds its text from crawled page content, so a new assignment is written inactive and applies to
nothing until someone activates it, instead of going live for every capability down the whole page tree with `--dry-run`
as the only way out. `--activate`, `--scope` and `--no-inherit` make the rest explicit, on a re-run as much as on the
first one. Activation is one-way: `--activate` works at any time, but a plain re-run never switches an activated
assignment back off, so a nightly job cannot undo a review. Everything that reviews assignments shows the parked ones
too: the page listing, the counts, the statistics, the check a re-run makes before overwriting a shared fragment, and
the Fragment Library, where a fragment used only by a parked assignment would otherwise look unused and safe to delete.


- **Bugfix**: A fragment written automatically respects the field length limits and leaves an entry in the system log.
That write bypasses TYPO3's own data handling for speed, so both are done explicitly, and machine-written content now
has an audit trail.


- **Bugfix "Calibration crawl"**: `aim:calibrateVoice` only crawls pages a website visitor could see. Hidden pages, pages
outside their publication dates, storage folders and the recycler are skipped, and so are content elements restricted to
a frontend user group in the fallback that reads elements directly. The check covers the page you point it at as much as
its subpages, so `--page` on a hidden page or a folder crawls nothing, and it covers a page's own access restriction,
not only that of its content.


- **Bugfix "Untrusted text"**: Text the model is asked to analyse is handed to it as data, not as instructions. The voice
calibration sample and the material the quality judge scores are wrapped in **explicit markers**, and the instructions name
those markers as untrusted content. Anything inside that looks like such a marker is defused first, so it cannot close
the wrapper and continue as an instruction, and the match is on the shape of a marker rather than one exact spelling,
which a lowercased, unspaced, hyphenated, full-width or invisibly split variant would otherwise walk straight past. Each
wrapper carries a random suffix generated for that one request, and the instructions quote it, so the model can tell a
real delimiter from one the content brought along. It matters most for the judge, whose score is aggregated per model
and decides whether a cheaper model is trusted with your requests.


#### Backend

- **Bugfix**: The Request Log and the Providers list work on MySQL and MariaDB. Their pagination counts and their
statistics are built in a way all engines accept, where both modules previously might fail to load on MySQL and
MariaDB entirely. (#27).


- **Bugfix**: The page tree filter in Prompt Management shows hidden pages and folders again. It no longer shares its
traversal with the calibration crawl, which deliberately skips anything a website visitor could not see, so selecting a
subtree keeps hidden pages, storage folders and everything below them, and with them the fragments assigned there.


- **Bugfix**: The Request Log's automatic refresh keeps the view you are on. It sends the filters, the sorting and the
page number.


- **Bugfix**: The delete confirmation names the record, and works on TYPO3 v14, which stopped supporting the way the
message was passed to it. Deleting a provider configuration destroys a stored credential, so the dialog says which one.


- **Bugfix**: The Providers list keeps its filter when you page. The pagination links carry the filter as well as the
sorting, so page two of a list filtered by title or provider is still filtered.


- **Bugfix**: A library fragment's visibility switch means the same as the two next to it: switched on means visible, as
it does on the assignment and on the provider configuration. It was labelled _Disable_, so switching it on hid the
fragment, which an editor sees side by side with its assignment.


- **Bugfix "Keyboard and screen reader"**: The listings can be used without a mouse and without sight. The icon-only
pagination controls carry names a screen reader reads out, their disabled placeholders are no longer announced as empty
links, the icon and action columns have headers, and the five explanations behind the Request Log's statistics are
reachable by keyboard.


- **Bugfix "Verify provider"**: A freshly verified provider's status looks like the rest of the column. The check now
renders its result in the same markup the server does.


- **Bugfix**: The Request Log says something when the page you asked for holds nothing. A bookmarked `&page=3` whose
rows the retention task has since pruned explains that and offers a way back.


- **Bugfix**: A model's enabled state is no longer carried by the colour of a dot alone. The toggle says which action it
offers, reports its state to assistive technology, and a failed toggle raises a message instead of writing to the
browser console, which matters for a control whose whole purpose is to keep a model out of every request.


- **Bugfix**: The _Calibrate Voice_ dialog names its own input field, and clicking Analyze with an empty one tells you
what to do. Failures in that dialog are announced rather than only displayed.


- **Documentation "Backend wording"**: Wording corrections across the backend. The Total Tokens explanation is
translatable. The "no providers" screen explains that a provider comes as a Composer package rather than telling the
administrator to install an extension. The Embedding and Image Generation chips have labels. The hint about pending
grades names a command path that exists in a Classic-mode installation too. Five unused labels are gone. A test checks
that every label the code asks for actually exists.


- **Bugfix**: The Request Log's status filter shows the status you picked. The dropdown keeps its selection on the next
render, so the list and the dropdown no longer disagree about what is being filtered.


#### TYPO3 12.4, 13.4 and 14

- **Bugfix "Module menu"**: On TYPO3 12.4 the three backend modules appear in the module menu again. Its menu is only two
levels deep, so on 12.4 the three are registered directly under Admin Tools rather than grouped under one "AiM" entry,
which left them reachable by URL only and showed a user granted just Prompt Management a link they were not allowed to
open.


- **Bugfix**: On TYPO3 12.4 the page setting "do not inherit prompt fragments" now works. TYPO3 reads only a fixed set of
page fields when it walks up the tree there, and this one is added to that set, so the checkbox takes effect instead of
leaving fragments from pages above in the prompt.


- **Bugfix**: On TYPO3 12.4 a provider configured through a site's settings is now found. The lookup reads the nested
YAML tree the documentation describes, where it previously asked in a way that only sees flat keys, answered no every
time  and silently never used the configuration, `--site` on the commands included.


- **Bugfix "Content extraction"**: On TYPO3 12.4 content extraction reads the fields the element actually displays, and
only those that hold prose. A heading-only element no longer contributes leftover body text, a value the frontend never
shows, to what is sent to the AI provider. Fields inside palettes are followed, an element that redefines a field's type
for itself is respected, and a third-party element's own text fields are picked up rather than only the three core ones.


#### Internals

- **Task Test matrix**: The test suite runs on TYPO3 12.4, 13.4 and 14, and the 12.4 leg of the CI installs for the
first time. Its dependencies could not be resolved at all, for three independent reasons, all three addressed in the
workflow, and eleven tests were written against behaviour that only holds from v13 on. The four defects the 12.4 leg
then found are fixed above.


- **Task Static analysis**: Static analysis and the coding standards check actually run, and both are part of the CI.
Their configuration was in the repository, but neither tool was a declared dependency, so neither could ever have been
executed, and the static analysis config pointed at the wrong directory. The 48 findings that already existed are
recorded in a baseline, so new code is held to the standard without a large mechanical cleanup first. Functional tests
can also run against MariaDB, and the CI gained a leg for it: SQLite answers a query against a column that does not
exist with the column name as a string instead of failing, so a whole class of database defect could not surface on the
previous SQLite-only matrix.


- **Task Changed signatures**: Four signatures changed, none of them on a method a consuming extension calls, listed
here because the classes are extendable and a subclass overriding one of them would fail on load rather than at runtime:
`PageTreeResolver` now takes its database connection as a constructor argument, the two `getQueryBuilderForDemand()`
methods on the provider and request log repositories take a second, optional argument, and `PromptPreviewService` and
`AiProvidersItemsProcFunc` each take one more constructor argument.


- **Bugfix Fragment cache**: The prompt fragment cache is registered where TYPO3 actually looks for it, so it exists at
all: every AI request used to rescan all installed extensions for fragments. Every access to it is guarded as well, so a
cache whose storage is not in place yet, which is what a deployment looks like before its schema update, costs the
caching and never the request.

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
