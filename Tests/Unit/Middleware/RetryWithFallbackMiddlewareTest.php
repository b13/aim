<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Middleware;

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\RequestContext;
use B13\Aim\Middleware\RetryWithFallbackMiddleware;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\FallbackChain;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The middleware is a shared service holding one dispatch's fallback chain,
 * which is the whole reason these tests exist: state that outlives a dispatch
 * sends a later request to a provider nobody asked for.
 */
final class RetryWithFallbackMiddlewareTest extends TestCase
{
    /**
     * A failing primary must still reach its fallback. Without this, the two
     * tests below would also pass on a middleware that never falls back at all.
     */
    #[Test]
    public function aFailingPrimaryReachesItsFallback(): void
    {
        $middleware = new RetryWithFallbackMiddleware(new NullLogger());
        $middleware->setFallbackChain($this->chain());

        $attempts = 0;
        $handler = $this->handler(function () use (&$attempts): TextResponse {
            $attempts++;

            return $attempts === 1
                ? new TextResponse('', errors: ['primary is down'])
                : new TextResponse('from the fallback');
        });

        $response = $middleware->process(
            $this->createStub(AiRequestInterface::class),
            $this->createStub(AiProviderInterface::class),
            $this->configuration(1, 'openai'),
            $handler,
        );

        self::assertSame(2, $attempts);
        self::assertSame('from the fallback', $response->content);
    }

    /**
     * Finding 21: the chain was kept on the shared instance, so a later
     * dispatch that set no chain of its own inherited the previous one and
     * swept providers the caller never asked for, with its own logging and
     * rate-limit consumption.
     */
    #[Test]
    public function theChainOfOneDispatchIsNotReusedByTheNext(): void
    {
        $middleware = new RetryWithFallbackMiddleware(new NullLogger());
        $middleware->setFallbackChain($this->chain());

        $firstAttempts = 0;
        $middleware->process(
            $this->createStub(AiRequestInterface::class),
            $this->createStub(AiProviderInterface::class),
            $this->configuration(1, 'openai'),
            $this->handler(function () use (&$firstAttempts): TextResponse {
                $firstAttempts++;

                return new TextResponse('', errors: ['everything is down']);
            }),
        );
        self::assertSame(2, $firstAttempts, 'The first dispatch should have tried the fallback.');

        // Second dispatch, same shared instance, no chain of its own.
        $secondAttempts = 0;
        $response = $middleware->process(
            $this->createStub(AiRequestInterface::class),
            $this->createStub(AiProviderInterface::class),
            $this->configuration(2, 'anthropic'),
            $this->handler(function () use (&$secondAttempts): TextResponse {
                $secondAttempts++;

                return new TextResponse('', errors: ['this one is down too']);
            }),
        );

        self::assertSame(1, $secondAttempts, 'A dispatch without a chain must not sweep the previous one.');
        self::assertSame(['this one is down too'], $response->errors);
    }

    /**
     * A governance refusal is about the caller, so every fallback would be
     * refused by the same rule. Sweeping them would burn rate-limit slots and
     * report the refusal against the last configuration tried.
     */
    #[Test]
    public function aGovernanceRefusalIsNotSweptThroughTheChain(): void
    {
        $middleware = new RetryWithFallbackMiddleware(new NullLogger());
        $middleware->setFallbackChain($this->chain());

        $context = new RequestContext();
        $attempts = 0;
        $handler = new AiMiddlewareHandler(
            function () use (&$attempts, $context): TextResponse {
                $attempts++;
                $context->governanceDenied = true;

                return new TextResponse('', errors: ['Access denied by governance rule']);
            },
            $context,
        );

        $response = $middleware->process(
            $this->createStub(AiRequestInterface::class),
            $this->createStub(AiProviderInterface::class),
            $this->configuration(1, 'openai'),
            $handler,
        );

        self::assertSame(1, $attempts, 'A refused request must not be retried against other providers.');
        self::assertSame(['Access denied by governance rule'], $response->errors);
        self::assertNull($context->fallbackInfo, 'A refusal must not be reported as a fallback.');
    }

    private function handler(callable $callback): AiMiddlewareHandler
    {
        return new AiMiddlewareHandler($callback, new RequestContext());
    }

    private function configuration(int $uid, string $provider): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => $uid,
            'ai_provider' => $provider,
            'model' => 'a-model',
            'disabled' => 0,
        ]);
    }

    /**
     * A primary plus one fallback, the smallest chain that can be swept.
     */
    private function chain(): FallbackChain
    {
        return new FallbackChain(
            new ResolvedProvider($this->manifest('openai'), $this->configuration(1, 'openai')),
            new ResolvedProvider($this->manifest('anthropic'), $this->configuration(2, 'anthropic')),
        );
    }

    private function manifest(string $identifier): AiProviderManifest
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($this->createStub(AiProviderInterface::class));

        return new AiProviderManifest(
            identifier: $identifier,
            name: ucfirst($identifier),
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [ConversationCapableInterface::class],
            serviceName: 'aim.symfony_ai.' . $identifier,
            container: $container,
        );
    }
}
