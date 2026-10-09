<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Middleware\Fixtures;

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\AiMiddlewareInterface;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;
use Symfony\Component\HttpClient\EventSourceHttpClient;

/**
 * The example middleware from Documentation/HttpClient.md, kept verbatim so
 * the documented behavior is covered by a test.
 */
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
