# Configuring a provider

How to fill in a provider configuration for a hosted service, a local model, a gateway or a proxy that wants its own credential, plus how to register a provider AiM does not know.

[Back to the README](../README.md)

## Filling in a provider configuration

Two fields decide where a request goes and how it authenticates:

| Field | What goes in it | Stored as |
|---|---|---|
| **Endpoint URL** | The base URL of a self-hosted or proxied provider. Leave empty for a hosted provider, which knows its own address. | Plain text |
| **API Key** | The credential, if the provider wants one. | Always encrypted |

Either one on its own is a valid configuration, and so is both together. Prefer *API Key* for the credential; if the host only accepts basic auth, see [If the provider wants the credential in the URL](#if-the-provider-wants-the-credential-in-the-url). The same two values can come from a site's `settings.yaml` instead, see [Configuring a provider in site settings instead](#configuring-a-provider-in-site-settings-instead).

Examples, using the provider identifiers as they appear in the *Provider* dropdown:

| Setup | Provider | Endpoint URL | API Key |
|---|---|---|---|
| OpenAI, hosted | `openai` | *(empty)* | `sk-proj-…` |
| Anthropic, hosted | `anthropic` | *(empty)* | `sk-ant-…` |
| Ollama on the same machine | `ollama` | `http://localhost:11434` | *(empty)* |
| Ollama in Docker, reached from a container | `ollama` | `http://host.docker.internal:11434` | *(empty)* |
| Ollama behind a reverse proxy that wants a token | `ollama` | `https://ollama.example.com` | the proxy token |
| An OpenAI-compatible gateway (Open WebUI, vLLM, LiteLLM) | `openresponses` | `https://gateway.example.com` | the gateway token |

Whether both fields are actually used depends on what the bridge's factory accepts. `ollama` takes an endpoint plus a credential, `openresponses` and `anthropic` take a base URL plus one, and the plain `openai` bridge takes a credential only, so it cannot be pointed at a self-hosted host at all. Pick `openresponses` for anything OpenAI-compatible that is not OpenAI itself.

## A self-hosted provider that requires a credential

For a bridge that ships no model catalogue of its own (Ollama, LM Studio, an OpenAI-compatible gateway), the model dropdown is filled by asking the host for its model list, at **`{Endpoint URL}/v1/models`**, sending *API Key* as a bearer token when one is set. A bridge that does ship a catalogue lists that instead and is never queried, so if you see a fixed set of vendor model names rather than your host's, the bridge you picked has its own list. Three consequences worth knowing:

**The endpoint has to be the base that `/v1/models` hangs off.** If your host serves the OpenAI-compatible list at `https://gateway.example.com/api/v1/models`, enter `https://gateway.example.com/api` and not the bare host.

**A failed lookup is remembered briefly.** A reachable host is cached for 15 minutes and an unreachable or refusing one for 60 seconds, so a wrong endpoint does not cost the full connect timeout on every render. After fixing the endpoint or the credential the list refreshes on the next load, since the cache is keyed by both; flush system caches to pick up a model added on the server sooner.

**Save the record before expecting models.** A host that wants a credential cannot be asked for its list until the credential is stored, so the *Model* field is deliberately not required. Enter the provider, endpoint and key, save, and the dropdown fills in on the next load. A configuration with no model yet is skipped by AiM exactly as a disabled one is, and the Providers overview marks it "no model" so it is not mistaken for a working setup.

So the full sequence for a gateway at `https://gateway.example.com/api` with token `sk-gw-…`:

1. Provider: `openresponses`
2. Endpoint URL: `https://gateway.example.com/api`
3. API Key: `sk-gw-…`
4. Model: leave empty, and save
5. Reopen the record; the *Model* dropdown now lists what the gateway offers. Pick one and save again.

Model lists are cached for 15 minutes (60 seconds if the host was unreachable), so if you add a model on the server and want it at once, flush the system caches.

## If the provider wants the credential in the URL

Some hosts only speak basic auth, where the credential belongs in the URL itself and a bearer token is rejected. You can enter such a URL directly:

**The user name has to stay in the URL.** That is what marks the credential as belonging in the URL: with `https://host` in the endpoint field and only a password in *API Key*, the value is sent as a bearer token instead, and a basic-auth host answers 401 without hinting at the cause. If you fill in both the URL's password and *API Key*, the key field wins and the save tells you the URL's password was discarded.

```
Endpoint URL:  https://svc:s3cret@gateway.example.com/v1
API Key:       (leave empty)
```

**On save the fields will look different, and that is intended.** The password half is moved into *API Key*, where it is encrypted like any other credential, and the endpoint keeps only its user half:

```
Endpoint URL:  https://svc@gateway.example.com/v1
API Key:       (a key is configured)
```

Nothing is lost. The credential is put back into the URL for each outbound request, in memory only, so it is never stored or displayed in a plaintext field. That trailing `svc@` is what tells AiM to authenticate this configuration through the URL rather than with a header, so leave it in place.

**Removing a stored key.** The button next to *API Key* marks the stored credential for deletion; it is applied when you save, and clicking again takes it back. This matters when you repoint a configuration at a different host: the stored key is kept by default and would be sent to the new one, so the save warns you when the endpoint changed and a key is still stored. Either replace it or remove it.

**Not supported: a key used as the user name.** Some providers, Stripe being the
best known, authenticate with the key in the basic-auth *user* position and an
empty password (`https://sk_live_...:@api.example.com`). AiM only ever moves the
password half out of the URL, so such a key would stay in the endpoint column,
which is plain text, would be written to the record history like any other
column value, and would be shown in the Providers list. Do not put one there.
Error messages do redact it, along with any other user-info in a URL, but that
is damage control and not storage. If you need such a provider, say so and it
can be supported properly: the shape is unambiguous, since an empty password
after a colon is distinguishable from a URL that carries no password at all.

**Changing the password later.** Either route works, and both end up in the encrypted column:

- Type the new password into *API Key*. Its field is always empty when you open the record, since a stored key is never shown again, so anything you type there replaces what is stored.
- Or type the endpoint again with the new password in it (`https://svc:new-secret@gateway.example.com/v1`) and leave *API Key* alone. On save the password moves out of the URL exactly as it did the first time.

One caveat for the second route: *API Key* is a password field, so a browser or password manager may autofill it without you noticing. Then the key field wins, your new password in the URL is discarded, and the old one stays in place. The save reports that, so read the message if you expected a change and nothing seems to have happened.

## Configuring a provider in site settings instead

A provider can also come from a site's `config/sites/<identifier>/settings.yaml`,
which is what `--site` on the CLI commands resolves. It is a fallback for simple
setups, used when no database configuration applies:

```yaml
ai:
  provider: openai
  apiKey: sk-...
  endpoint: https://gateway.example.com/v1   # self-hosted or a gateway
  model: gpt-4o
```

`provider` is required, and either `apiKey` or `endpoint` is enough on its own,
so an endpoint-only local provider is expressible. A credential written into
`endpoint` (`https://user:password@host`) is moved out of the URL the same way
the endpoint field does it, and `apiKey` wins if both are set.

**These values are not encrypted.** Unlike a database configuration, a site
settings file is configuration: usually version-controlled, and readable by
anyone who can read the repository. For anything you would rather not commit,
use a database configuration, or keep the value out of the file with an
environment placeholder.

## Bridge identifiers

Each bridge gets a short identifier, which is what you see in the provider select and what you pass to `->provider('openai:gpt-4o')`. For Symfony's own packages it comes from the package name (`symfony/ai-anthropic-platform` gives `anthropic`). For any other vendor the package name says nothing usable (`t3ppy/symfony-ai-platform` would give `t3ppy/symfonyai`, slash included), so the name the bridge declares for itself is used instead: `t3ppy`. That name is read while the container is compiled, without a credential and without a network call. Every bridge measured carries it as the default of a `$name` parameter on its factory, which reflection reads without executing any bridge code; only a bridge that declares no such parameter is built and asked through `ProviderInterface::getName()`. A bridge that refuses to be built without a real credential, or that answers with something unusable as an identifier, keeps the name derived from its package.

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

AiM auto-discovers any installed Composer package of type `symfony-ai-platform`. For each bridge:

1. Reads the PSR-4 namespace from the package's `composer.json`
2. Instantiates the bridge's `ModelCatalog` to read models and per-model capabilities
3. Maps Symfony AI `Capability` enums to AiM capability interfaces
4. Sanitizes model names for TCA compatibility (no colons)
5. Detects via reflection which arguments the factory declares (`apiKey`, `endpoint`/`hostUrl`/`baseUrl`, or both) and passes whichever of the two fields are filled
6. Registers a `SymfonyAiPlatformAdapter` as an AiM provider

Install a bridge, flush caches. The provider appears automatically in the backend module with all its models.
