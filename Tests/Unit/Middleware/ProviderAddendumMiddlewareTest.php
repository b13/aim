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
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\ProviderAddendumMiddleware;
use B13\Aim\Middleware\RequestContext;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\ImageGenerationRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderAddendumMiddlewareTest extends TestCase
{
    private function createConfig(string $systemPromptAddition = ''): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'model' => 'gpt-4o',
            'system_prompt_addition' => $systemPromptAddition,
        ]);
    }

    /**
     * @param list<string> $composedPromptParts what TonePromptCompositionMiddleware
     *        would have already recorded on the shared context
     * @param bool $promptOverrideApplied what TonePromptCompositionMiddleware
     *        would have already recorded when systemPromptOverride was used
     */
    private function capturingHandler(?AiRequestInterface &$captured, array $composedPromptParts = [], bool $promptOverrideApplied = false): AiMiddlewareHandler
    {
        $context = new RequestContext();
        $context->composedPromptParts = $composedPromptParts;
        $context->promptOverrideApplied = $promptOverrideApplied;
        return new AiMiddlewareHandler(static function (AiRequestInterface $request) use (&$captured): TextResponse {
            $captured = $request;
            return new TextResponse('ok');
        }, $context);
    }

    #[Test]
    public function appendsTheAddendumAfterWhateverToneCompositionAlreadySet(): void
    {
        $middleware = new ProviderAddendumMiddleware();
        $config = $this->createConfig('Keep responses under 100 words.');
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: 'Task + tone already composed.');

        $captured = null;
        $middleware->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->capturingHandler($captured, ['Task + tone already composed.']),
        );

        self::assertSame(
            "Task + tone already composed.\n\nKeep responses under 100 words.",
            $captured->getSystemPrompt(),
        );
    }

    #[Test]
    public function doesNothingWhenThereIsNoAddendum(): void
    {
        $middleware = new ProviderAddendumMiddleware();
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: 'Unchanged.');

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame($request, $captured);
    }

    #[Test]
    public function skipsTheAddendumWhenItExactlyDuplicatesAPartToneCompositionAlreadyIncluded(): void
    {
        // Cross-phase dedup: the tone phase already included this exact
        // text (e.g. a registry fragment happened to match the addendum
        // wording); appending it again would send it to the provider twice.
        $middleware = new ProviderAddendumMiddleware();
        $config = $this->createConfig('Never use exclamation marks.');
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: "Task.\n\nNever use exclamation marks.");

        $captured = null;
        $middleware->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->capturingHandler($captured, ['Task.', 'Never use exclamation marks.']),
        );

        self::assertSame($request, $captured);
    }

    #[Test]
    public function disableSystemPromptCompositionSkipsTheAddendumToo(): void
    {
        $middleware = new ProviderAddendumMiddleware();
        $config = $this->createConfig('Must not leak through.');
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Hi',
            systemPrompt: 'Exactly this, untouched.',
            disableAutomaticSystemPrompt: true,
        );

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame($request, $captured);
    }

    #[Test]
    public function systemPromptOverrideSkipsTheAddendumTooBecauseTotalOverrideMeansTotal(): void
    {
        // ProviderAddendumMiddleware no longer re-derives "was an override
        // given" from the request itself: it reads promptOverrideApplied,
        // which TonePromptCompositionMiddleware (running earlier in the real
        // pipeline) would already have set on the shared context.
        $middleware = new ProviderAddendumMiddleware();
        $config = $this->createConfig('Must not leak through.');
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Hi',
            systemPrompt: 'Task.',
            systemPromptOverride: ['My own tone.'],
        );

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured, promptOverrideApplied: true));

        self::assertSame($request, $captured);
    }

    #[Test]
    public function appendsTheAddendumOntoPromptForImageGeneration(): void
    {
        $middleware = new ProviderAddendumMiddleware();
        $config = $this->createConfig('Use the vivid style preset.');
        $request = new ImageGenerationRequest(configuration: $config, prompt: 'A mountain landscape at sunset');

        $captured = null;
        $middleware->process(
            $request,
            $this->createMock(AiProviderInterface::class),
            $config,
            $this->capturingHandler($captured, ['A mountain landscape at sunset']),
        );

        self::assertInstanceOf(ImageGenerationRequest::class, $captured);
        self::assertSame(
            "A mountain landscape at sunset\n\nUse the vivid style preset.",
            $captured->prompt,
        );
    }

    #[Test]
    public function noOpsForRequestTypesWithoutPageContextSupport(): void
    {
        $middleware = new ProviderAddendumMiddleware();
        $config = $this->createConfig('Must not leak into embeddings.');
        $request = new EmbeddingRequest(configuration: $config, input: ['text']);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame($request, $captured);
    }
}
