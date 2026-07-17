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

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\CostTrackingMiddleware;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CostTrackingMiddlewareTest extends TestCase
{
    private function createConfig(int $uid = 1): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => $uid,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ]);
    }

    #[Test]
    public function updatesTotalCostForSuccessfulNonStreamingResponse(): void
    {
        $repository = $this->createMock(ProviderConfigurationRepository::class);
        $repository->expects(self::once())->method('updateTotalCost')->with(1, 0.05);

        $middleware = new CostTrackingMiddleware($repository);
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi');
        $response = new TextResponse('hello', new AiUsageStatistics(cost: 0.05));
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);
    }

    #[Test]
    public function doesNotUpdateCostForEphemeralConfiguration(): void
    {
        $repository = $this->createMock(ProviderConfigurationRepository::class);
        $repository->expects(self::never())->method('updateTotalCost');

        $middleware = new CostTrackingMiddleware($repository);
        $config = $this->createConfig(0);
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi');
        $response = new TextResponse('hello', new AiUsageStatistics(cost: 0.05));
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);
    }

    #[Test]
    public function doesNotUpdateCostSynchronouslyForStreamingResponse(): void
    {
        // Cost is a placeholder (0.0) until the caller drains the stream —
        // updating now would be a silent no-op that never gets corrected.
        // Must defer instead of skipping it forever.
        $repository = $this->createMock(ProviderConfigurationRepository::class);
        $repository->expects(self::never())->method('updateTotalCost');

        $middleware = new CostTrackingMiddleware($repository);
        $config = $this->createConfig();
        $request = new ConversationRequest(
            configuration: $config,
            messages: [new UserMessage('Hi')],
            stream: true,
        );
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'chunk';
        })(), $config);
        $response = new ConversationResponse('', streamIterator: $streamIterator);
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);
    }

    #[Test]
    public function appliesRealCostOffTheDrainedIteratorOnceStreamIsConsumed(): void
    {
        // applyStreamedCost() is what the shutdown function registered by
        // deferCostTracking() actually calls — tested directly here since
        // PHP only invokes shutdown functions at the end of the whole test
        // process (same constraint GraderMiddlewareTest works under).
        $usageChunk = new class {
            public function getPromptTokens(): int
            {
                return 1000;
            }

            public function getCompletionTokens(): int
            {
                return 500;
            }
        };
        $configWithCost = new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
            'input_token_cost' => 1.0,
            'output_token_cost' => 2.0,
        ]);
        $streamIterator = new StreamChunkIterator((function () use ($usageChunk): \Generator {
            yield 'chunk';
            yield $usageChunk;
        })(), $configWithCost);
        // Drain the stream, simulating the controller sending chunks to the client.
        iterator_to_array($streamIterator, false);

        $expectedCost = (1000 / 1_000_000 * 1.0) + (500 / 1_000_000 * 2.0);
        $repository = $this->createMock(ProviderConfigurationRepository::class);
        $repository->expects(self::once())->method('updateTotalCost')->with(1, $expectedCost);

        $middleware = new CostTrackingMiddleware($repository);
        (new \ReflectionMethod($middleware, 'applyStreamedCost'))->invoke($middleware, $streamIterator, 1);
    }

    #[Test]
    public function doesNotApplyCostWhenDrainedIteratorHasNoUsage(): void
    {
        $config = $this->createConfig();
        $repository = $this->createMock(ProviderConfigurationRepository::class);
        $repository->expects(self::never())->method('updateTotalCost');

        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'chunk without a trailing usage delta';
        })(), $config);
        iterator_to_array($streamIterator, false);

        $middleware = new CostTrackingMiddleware($repository);
        (new \ReflectionMethod($middleware, 'applyStreamedCost'))->invoke($middleware, $streamIterator, 1);
    }
}
