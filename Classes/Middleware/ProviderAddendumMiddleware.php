<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Middleware;

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Prompt\PromptComposer;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ImageGenerationRequest;
use B13\Aim\Request\SupportsPageContextInterface;
use B13\Aim\Request\SupportsSystemPromptInterface;
use B13\Aim\Response\TextResponse;

/**
 * Appends the provider-specific addendum (ProviderConfiguration::$systemPromptAddition)
 * onto whatever TonePromptCompositionMiddleware already composed.
 *
 * Split out from tone composition, specifically because the addendum is the
 * *only* layer of the old combined design that genuinely needs the final,
 * post-rerouting provider configuration. Priority 10, below
 * CapabilityValidationMiddleware (50) and SmartRoutingMiddleware (75) so
 * $configuration here is guaranteed final, but above GraderMiddleware
 * (-600) / RequestLoggingMiddleware (-700) / CostTrackingMiddleware (-800)
 * so what gets logged/graded/sent to the provider includes it.
 */
#[AsAiMiddleware(priority: 10)]
final class ProviderAddendumMiddleware implements AiMiddlewareInterface
{
    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        if (!$request instanceof SupportsPageContextInterface || $request->isAutomaticPromptCompositionDisabled()) {
            return $next->handle($request, $provider, $configuration);
        }

        if ($next->context->promptOverrideApplied) {
            return $next->handle($request, $provider, $configuration);
        }

        $addendum = trim($configuration->systemPromptAddition);
        if (PromptComposer::isRedundant($addendum, $next->context->composedPromptParts)) {
            return $next->handle($request, $provider, $configuration);
        }

        if ($request instanceof SupportsSystemPromptInterface) {
            $request = $request->withSystemPrompt(PromptComposer::compose([$request->getSystemPrompt(), $addendum]));
        } elseif ($request instanceof ImageGenerationRequest) {
            $request = $request->withPrompt(PromptComposer::compose([$request->prompt, $addendum]));
        }

        return $next->handle($request, $provider, $configuration);
    }
}
