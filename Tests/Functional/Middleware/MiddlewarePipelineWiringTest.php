<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Middleware;

use B13\Aim\Middleware\AiMiddlewareInterface;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Middleware\CapabilityValidationMiddleware;
use B13\Aim\Middleware\GraderMiddleware;
use B13\Aim\Middleware\ProviderAddendumMiddleware;
use B13\Aim\Middleware\SmartRoutingMiddleware;
use B13\Aim\Middleware\TonePromptCompositionMiddleware;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Every other test covering TonePromptCompositionMiddleware/ProviderAddendumMiddleware
 * constructs them directly with mocked/real dependencies — none of them
 * prove the DI compiler pass (#[AsAiMiddleware(priority: ...)]) actually
 * wires both into the real, container-assembled pipeline at the intended
 * positions. A mistake in Configuration/Services.yaml or either priority
 * attribute would pass every one of those tests and still silently do
 * nothing (or the wrong thing) in production. This test inspects the real,
 * container-built AiMiddlewarePipeline instead.
 *
 * The ordering asserted here isn't incidental — it's the whole point of
 * having split what used to be one SystemPromptResolutionMiddleware into
 * two: TonePromptCompositionMiddleware must run *before* SmartRoutingMiddleware
 * so complexity classification (and its cheaper-model downgrade decision)
 * sees the real, fragment-inflated prompt — while ProviderAddendumMiddleware
 * must still run *after* CapabilityValidationMiddleware/SmartRoutingMiddleware
 * so it only ever sees the final, post-rerouting provider configuration.
 */
final class MiddlewarePipelineWiringTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    /**
     * @return list<class-string<AiMiddlewareInterface>>
     */
    private function getAssembledMiddlewareClasses(): array
    {
        $pipeline = $this->get(AiMiddlewarePipeline::class);
        $property = new \ReflectionProperty(AiMiddlewarePipeline::class, 'middlewares');
        return array_map(get_class(...), $property->getValue($pipeline));
    }

    #[Test]
    public function bothMiddlewaresAreRegisteredInTheRealPipeline(): void
    {
        $classes = $this->getAssembledMiddlewareClasses();

        self::assertContains(TonePromptCompositionMiddleware::class, $classes);
        self::assertContains(ProviderAddendumMiddleware::class, $classes);
    }

    #[Test]
    public function tonePromptCompositionRunsBeforeSmartRoutingSoComplexityClassificationSeesTheRealPrompt(): void
    {
        $classes = $this->getAssembledMiddlewareClasses();

        $toneIndex = array_search(TonePromptCompositionMiddleware::class, $classes, true);
        $smartRoutingIndex = array_search(SmartRoutingMiddleware::class, $classes, true);

        self::assertNotFalse($toneIndex);
        self::assertNotFalse($smartRoutingIndex);
        self::assertLessThan($smartRoutingIndex, $toneIndex);
    }

    #[Test]
    public function providerAddendumIsPositionedBetweenCapabilityValidationAndGrader(): void
    {
        // Pre-sorted highest-priority-first: CapabilityValidationMiddleware
        // (50) must come before ProviderAddendumMiddleware (10), which must
        // come before GraderMiddleware (-600) — so $configuration is final
        // (post-rerouting) by the time the addendum is read, but the
        // addendum is still part of what gets logged/graded/sent.
        $classes = $this->getAssembledMiddlewareClasses();

        $capabilityValidationIndex = array_search(CapabilityValidationMiddleware::class, $classes, true);
        $addendumIndex = array_search(ProviderAddendumMiddleware::class, $classes, true);
        $graderIndex = array_search(GraderMiddleware::class, $classes, true);

        self::assertNotFalse($capabilityValidationIndex);
        self::assertNotFalse($addendumIndex);
        self::assertNotFalse($graderIndex);
        self::assertLessThan($addendumIndex, $capabilityValidationIndex);
        self::assertLessThan($graderIndex, $addendumIndex);
    }

    #[Test]
    public function smartRoutingRunsBeforeProviderAddendumToo(): void
    {
        $classes = $this->getAssembledMiddlewareClasses();

        $smartRoutingIndex = array_search(SmartRoutingMiddleware::class, $classes, true);
        $addendumIndex = array_search(ProviderAddendumMiddleware::class, $classes, true);

        self::assertNotFalse($smartRoutingIndex);
        self::assertNotFalse($addendumIndex);
        self::assertLessThan($addendumIndex, $smartRoutingIndex);
    }
}
