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
use B13\Aim\Middleware\RequestContext;
use B13\Aim\Middleware\TonePromptCompositionMiddleware;
use B13\Aim\Prompt\PagePromptResolver;
use B13\Aim\Prompt\PromptFragmentRegistry;
use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Prompt\UserPromptFragmentResolver;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\ImageGenerationRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class TonePromptCompositionMiddlewareTest extends TestCase
{
    private function createConfig(): ProviderConfiguration
    {
        return new ProviderConfiguration(['uid' => 1, 'ai_provider' => 'openai', 'model' => 'gpt-4o']);
    }

    /**
     * @return array{0: TonePromptCompositionMiddleware, 1: PagePromptResolver&MockObject, 2: UserPromptFragmentResolver&MockObject, 3: PromptFragmentRegistry&MockObject, 4: ExtensionConfiguration&MockObject}
     */
    private function createMiddleware(): array
    {
        $pageResolver = $this->createMock(PagePromptResolver::class);
        $userFragmentResolver = $this->createMock(UserPromptFragmentResolver::class);
        $registry = $this->createMock(PromptFragmentRegistry::class);
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);

        $middleware = new TonePromptCompositionMiddleware($pageResolver, $userFragmentResolver, $registry, $extensionConfiguration);
        return [$middleware, $pageResolver, $userFragmentResolver, $registry, $extensionConfiguration];
    }

    /**
     * Captures the request forwarded to $next and the shared RequestContext
     * so tests can inspect composedPromptParts too.
     *
     * @param-out RequestContext $context
     */
    private function capturingHandler(?AiRequestInterface &$captured, ?RequestContext &$context = null): AiMiddlewareHandler
    {
        $context = new RequestContext();
        return new AiMiddlewareHandler(static function (AiRequestInterface $request) use (&$captured): TextResponse {
            $captured = $request;
            return new TextResponse('ok');
        }, $context);
    }

    #[Test]
    public function composesCallerPromptPageToneUserFragmentsAndRegistryFragmentsInOrderWithoutAddendum(): void
    {
        [$middleware, $pageResolver, $userFragmentResolver, $registry] = $this->createMiddleware();
        $pageResolver->method('resolve')->with(5, PromptFragmentScope::Text)->willReturn('Formal, third-person tone.');
        $userFragmentResolver->method('getFragments')->with(PromptFragmentScope::Text)->willReturn(['Assigned to this editor.']);
        $registry->method('getFragments')->with(PromptFragmentScope::Text)->willReturn(['Never use exclamation marks.']);

        // A provider addendum is set, but this middleware must never include
        // it . That's ProviderAddendumMiddleware's job, running later.
        $config = new ProviderConfiguration(['uid' => 1, 'ai_provider' => 'openai', 'model' => 'gpt-4o', 'system_prompt_addition' => 'Must not appear here.']);
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: 'Generate alt text.', pageId: 5);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame(
            "Generate alt text.\n\nFormal, third-person tone.\n\nAssigned to this editor.\n\nNever use exclamation marks.",
            $captured->getSystemPrompt(),
        );
    }

    #[Test]
    public function recordsTheDedupedPartsOnTheSharedContextForTheAddendumPhaseToReadLater(): void
    {
        [$middleware, $pageResolver, $userFragmentResolver, $registry] = $this->createMiddleware();
        $pageResolver->method('resolve')->willReturn('Tone text.');
        $userFragmentResolver->method('getFragments')->willReturn(['Tone text.']); // exact duplicate of the tone
        $registry->method('getFragments')->willReturn(['Registry text.']);

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: 'Task.', pageId: 5);

        $captured = null;
        $context = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured, $context));

        self::assertSame(['Task.', 'Tone text.', 'Registry text.'], $context->composedPromptParts);
    }

    #[Test]
    public function fallsBackToGlobalDefaultWhenRequestHasNoPageContext(): void
    {
        [$middleware, $pageResolver, , , $extensionConfiguration] = $this->createMiddleware();
        $pageResolver->expects(self::never())->method('resolve');
        $extensionConfiguration->method('get')->with('aim', 'defaultSystemPrompt')->willReturn('Global fallback tone.');

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: 'Generate alt text.', pageId: null);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame("Generate alt text.\n\nGlobal fallback tone.", $captured->getSystemPrompt());
    }

    #[Test]
    public function registeredFragmentsApplyRegardlessOfPageContext(): void
    {
        [$middleware, $pageResolver, , $registry] = $this->createMiddleware();
        $pageResolver->expects(self::never())->method('resolve');
        $registry->method('getFragments')->with(PromptFragmentScope::Text)->willReturn(['Always mention accessibility.']);

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: 'Generate alt text.', pageId: null);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame("Generate alt text.\n\nAlways mention accessibility.", $captured->getSystemPrompt());
    }

    #[Test]
    public function userFragmentsApplyRegardlessOfPageContext(): void
    {
        [$middleware, $pageResolver, $userFragmentResolver] = $this->createMiddleware();
        $pageResolver->expects(self::never())->method('resolve');
        $userFragmentResolver->method('getFragments')->with(PromptFragmentScope::Text)->willReturn(['Assigned via user TSconfig.']);

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', systemPrompt: 'Generate alt text.', pageId: null);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame("Generate alt text.\n\nAssigned via user TSconfig.", $captured->getSystemPrompt());
    }

    #[Test]
    public function doesNotRewriteRequestWhenComposedPromptIsUnchanged(): void
    {
        [$middleware] = $this->createMiddleware();
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', pageId: 5);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame($request, $captured);
    }

    #[Test]
    public function disableSystemPromptCompositionSkipsEverythingAndLeavesContextUntouched(): void
    {
        [$middleware, $pageResolver, $userFragmentResolver, $registry] = $this->createMiddleware();
        $pageResolver->expects(self::never())->method('resolve');
        $userFragmentResolver->expects(self::never())->method('getFragments');
        $registry->expects(self::never())->method('getFragments');

        $config = $this->createConfig();
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Hi',
            systemPrompt: 'Exactly this, untouched.',
            pageId: 5,
            disableAutomaticSystemPrompt: true,
        );

        $captured = null;
        $context = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured, $context));

        self::assertSame($request, $captured);
        self::assertSame([], $context->composedPromptParts);
    }

    #[Test]
    public function systemPromptOverrideTotallyReplacesToneUserAndRegistry(): void
    {
        [$middleware, $pageResolver, $userFragmentResolver, $registry] = $this->createMiddleware();
        $pageResolver->expects(self::never())->method('resolve');
        $userFragmentResolver->expects(self::never())->method('getFragments');
        $registry->expects(self::never())->method('getFragments');

        $config = $this->createConfig();
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Hi',
            systemPrompt: 'Generate alt text.',
            pageId: 5,
            systemPromptOverride: ['Use a playful tone just for this call.', 'Keep it under 20 words.'],
        );

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame(
            "Generate alt text.\n\nUse a playful tone just for this call.\n\nKeep it under 20 words.",
            $captured->getSystemPrompt(),
        );
    }

    #[Test]
    public function toleratesAMalformedOverrideConstructedDirectlyOnTheDto(): void
    {
        [$middleware] = $this->createMiddleware();
        $config = $this->createConfig();
        $request = new TextGenerationRequest(
            configuration: $config,
            prompt: 'Hi',
            systemPrompt: 'Generate alt text.',
            systemPromptOverride: ['Valid fragment.', 42, '  '],
        );

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame("Generate alt text.\n\nValid fragment.\n\n42", $captured->getSystemPrompt());
    }

    #[Test]
    public function splicesTonePlusUserFragmentsPlusRegistryFragmentsIntoPromptForImageGeneration(): void
    {
        [$middleware, $pageResolver, $userFragmentResolver, $registry] = $this->createMiddleware();
        $pageResolver->method('resolve')->with(5, PromptFragmentScope::ImageGeneration)->willReturn(null);
        $userFragmentResolver->method('getFragments')->with(PromptFragmentScope::ImageGeneration)->willReturn(['Assigned watermark policy.']);
        $registry->method('getFragments')->with(PromptFragmentScope::ImageGeneration)
            ->willReturn(['Add a small diagonal watermark reading "DRAFT".']);

        $config = $this->createConfig();
        $request = new ImageGenerationRequest(configuration: $config, prompt: 'A mountain landscape at sunset', pageId: 5);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertInstanceOf(ImageGenerationRequest::class, $captured);
        self::assertSame(
            "A mountain landscape at sunset\n\nAssigned watermark policy.\n\nAdd a small diagonal watermark reading \"DRAFT\".",
            $captured->prompt,
        );
    }

    #[Test]
    public function leavesImageGenerationPromptUntouchedWhenNothingResolves(): void
    {
        [$middleware] = $this->createMiddleware();
        $config = $this->createConfig();
        $request = new ImageGenerationRequest(configuration: $config, prompt: 'A mountain landscape', pageId: 5);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame($request, $captured);
    }

    #[Test]
    public function noOpsForRequestTypesWithoutSystemPromptOrPageContextSupport(): void
    {
        [$middleware, $pageResolver, $userFragmentResolver, $registry] = $this->createMiddleware();
        $pageResolver->expects(self::never())->method('resolve');
        $userFragmentResolver->expects(self::never())->method('getFragments');
        $registry->expects(self::never())->method('getFragments');

        $config = $this->createConfig();
        $request = new EmbeddingRequest(configuration: $config, input: ['text']);

        $captured = null;
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $this->capturingHandler($captured));

        self::assertSame($request, $captured);
    }
}
