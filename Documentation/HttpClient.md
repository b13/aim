# Modifying the HTTP client

How to swap or tune the HTTP client Symfony AI uses for provider requests, for example to raise the timeout.

[Back to the README](../README.md)

## How the client is chosen

By default, Symfony AI uses an `EventSourceHttpClient`, which each bridge's factory class instantiates and configures for its own connection. A pre-configured client can be passed to the constructor of the bridge class instead.

## Setting your own client

`B13\Aim\Domain\Model\ProviderConfiguration` holds the settings of the chosen provider, taken from the database record or the site settings. Its `httpClient` property is `null` by default. If you set it, it must be an implementation of `Symfony\Contracts\HttpClient\HttpClientInterface`, and Symfony AI uses it instead of creating its own.

## Example: raising the timeout

A common use case is a longer timeout, which is easiest to do in an AiM middleware (see [Custom Middleware](Pipeline.md#custom-middleware)).

The middleware below runs early in the pipeline and checks whether an `httpClient` has already been set. If not, it creates a new `EventSourceHttpClient` with a timeout of 900 seconds. If one exists, it sets the same timeout on the existing client:

```php
use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Middleware\AiMiddlewareInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;

#[AsAiMiddleware(priority: 900)]
class HttpClientMiddleware implements AiMiddlewareInterface
{
    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $configuration->httpClient = ($configuration->httpClient ?? new EventSourceHttpClient())
            ->withOptions(['timeout' => 900.0]);

        return $next->handle($request, $provider, $configuration);
    }
}
```

Priority `900` places it above every built-in middleware, so all of them, including `RetryWithFallbackMiddleware`, work with the modified client.

This way other global HTTP client settings can be set, like proxy or SOCKS5 settings.

Another usecase as well could be to mock HTTP requests for testing to prevent actual HTTP requests from being made.
