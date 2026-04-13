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
use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;

/**
 * The innermost middleware that dispatches to the actual AI provider.
 *
 * Routes the request to the correct capability method based on request type.
 * This should always be the last middleware in the chain (lowest priority).
 */
#[AsAiMiddleware(priority: -1000)]
final class CoreDispatchMiddleware implements AiMiddlewareInterface
{
    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        // During fallback, the middleware chain passes a different configuration
        // than the one embedded in the request. Rebuild the request so the
        // provider adapter uses the correct API key and model.
        if ($request->getConfiguration()->uid !== $configuration->uid
            || $request->getConfiguration()->model !== $configuration->model
        ) {
            $request = $request->withConfiguration($configuration);
        }

        return match (true) {
            $request instanceof VisionRequest && $provider instanceof VisionCapableInterface
                => $provider->processVisionRequest($request),
            $request instanceof ToolCallingRequest && $provider instanceof ToolCallingCapableInterface
                => $provider->processToolCallingRequest($request),
            $request instanceof ConversationRequest && $provider instanceof ConversationCapableInterface
                => $provider->processConversationRequest($request),
            $request instanceof TextGenerationRequest && $provider instanceof TextGenerationCapableInterface
                => $provider->processTextGenerationRequest($request),
            $request instanceof TranslationRequest && $provider instanceof TranslationCapableInterface
                => $provider->processTranslationRequest($request),
            $request instanceof EmbeddingRequest && $provider instanceof EmbeddingCapableInterface
                => $provider->processEmbeddingRequest($request),
            default => throw new \LogicException(sprintf(
                'Cannot dispatch request of type "%s" to provider "%s". No matching capability found.',
                get_class($request),
                $configuration->providerIdentifier,
            ), 1773874262),
        };
    }
}
