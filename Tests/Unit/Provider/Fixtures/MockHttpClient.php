<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Provider\Fixtures;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * This MockHttpClient *must* only used be in the testing of the HttpClientEventTest.
 * This Class does not have any other purpose or a complete implementation!
 */

class MockHttpClient implements HttpClientInterface, ResetInterface
{
    private ResponseInterface|\Closure|iterable|null $responseFactory; // @phpstan-ignore property.onlyWritten
    private array $defaultOptions = [];

    /**
     * @param callable|callable[]|ResponseInterface|ResponseInterface[]|iterable|null $responseFactory
     */
    public function __construct(callable|iterable|ResponseInterface|null $responseFactory = null, ?string $baseUri = 'https://example.com')
    {
        $this->setResponseFactory($responseFactory);
        $this->defaultOptions = self::OPTIONS_DEFAULTS;
        $this->defaultOptions['base_uri'] = $baseUri;
    }
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return new class implements ResponseInterface {
            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [
                    'Content-Type' => ['application/json'],
                ];
            }

            public function getContent(bool $throw = true): string
            {
                return '{}';
            }

            public function toArray(bool $throw = true): array
            {
                return [];
            }

            public function cancel(): void {}

            public function getInfo(?string $type = null): mixed
            {
                return null;
            }
        };

    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('MockHttpClient::stream() is not implemented.');
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        ArrayUtility::mergeRecursiveWithOverrule($clone->defaultOptions, $options);
        return $clone;
    }

    public function reset(): void {}

    public function setResponseFactory($responseFactory): void
    {
        if ($responseFactory instanceof ResponseInterface) {
            $responseFactory = [$responseFactory];
        }

        if (!$responseFactory instanceof \Iterator && $responseFactory !== null && !is_callable($responseFactory)) {
            $responseFactory = (static function () use ($responseFactory) {
                yield from $responseFactory;
            })();
        }

        $this->responseFactory = !is_callable($responseFactory) ? $responseFactory : $responseFactory(...);
    }

}
