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
use B13\Aim\Event\AiRequestReroutedEvent;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\DisabledModelRegistry;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates that the current provider supports the required capability
 * for the incoming request. If not, auto-reroutes to a capable provider.
 *
 * This is the first line of defence against user misconfiguration,
 * e.g. sending a VisionRequest to a text-only provider. Instead of
 * failing deep in the pipeline, this middleware catches the mismatch
 * early and transparently reroutes.
 *
 * Runs just inside RetryWithFallbackMiddleware so it can swap the
 * provider/configuration before the rest of the chain executes.
 */
#[AsAiMiddleware(priority: 50)]
final class CapabilityValidationMiddleware implements AiMiddlewareInterface
{
    private const REQUEST_CAPABILITY_MAP = [
        VisionRequest::class => VisionCapableInterface::class,
        ToolCallingRequest::class => ToolCallingCapableInterface::class,
        ConversationRequest::class => ConversationCapableInterface::class,
        TextGenerationRequest::class => TextGenerationCapableInterface::class,
        TranslationRequest::class => TranslationCapableInterface::class,
        EmbeddingRequest::class => EmbeddingCapableInterface::class,
    ];

    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly DisabledModelRegistry $disabledModelRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        // Block disabled models
        if ($this->disabledModelRegistry->isDisabled($configuration->providerIdentifier, $configuration->model)) {
            $this->logger->warning(sprintf(
                'Model "%s" of provider "%s" is disabled. Rejecting request.',
                $configuration->model,
                $configuration->providerIdentifier,
            ));
            return new TextResponse('', errors: [sprintf(
                'Model "%s" of provider "%s" has been disabled by the administrator.',
                $configuration->model,
                $configuration->providerIdentifier,
            )]);
        }

        $requiredCapability = $this->resolveRequiredCapability($request);
        if ($requiredCapability === null || $provider instanceof $requiredCapability) {
            return $next->handle($request, $provider, $configuration);
        }

        // Provider does not support the required capability — try to reroute
        $shortCapability = substr(strrchr($requiredCapability, '\\') ?: $requiredCapability, 1);
        $this->logger->warning(sprintf(
            'Provider "%s" (configuration %d) does not support %s. Attempting reroute.',
            $configuration->providerIdentifier,
            $configuration->uid,
            $shortCapability,
        ));

        try {
            $resolvedProvider = $this->providerResolver->resolveForCapability($requiredCapability);
            // Guard: don't reroute to the same provider that already failed
            if ($resolvedProvider->configuration->uid === $configuration->uid) {
                return new TextResponse('', errors: [sprintf(
                    'Provider "%s" does not support %s and no alternative provider is available.',
                    $configuration->providerIdentifier,
                    $shortCapability,
                )]);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Reroute failed: no alternative provider supports %s. %s',
                $shortCapability,
                $e->getMessage(),
            ));
            return new TextResponse('', errors: [sprintf(
                'Provider "%s" does not support %s and no alternative provider is available.',
                $configuration->providerIdentifier,
                $shortCapability,
            )]);
        }

        $reason = sprintf(
            'Rerouted from "%s" to "%s": original provider lacks %s',
            $configuration->providerIdentifier,
            $resolvedProvider->configuration->providerIdentifier,
            $shortCapability,
        );

        $this->logger->info($reason);

        $this->eventDispatcher->dispatch(new AiRequestReroutedEvent(
            $request,
            $configuration,
            $resolvedProvider->configuration,
            $requiredCapability,
            $reason,
        ));

        return $next->handle(
            $request,
            $resolvedProvider->manifest->getInstance(),
            $resolvedProvider->configuration,
        );
    }

    /**
     * @return class-string|null
     */
    private function resolveRequiredCapability(AiRequestInterface $request): ?string
    {
        foreach (self::REQUEST_CAPABILITY_MAP as $requestClass => $capabilityClass) {
            if ($request instanceof $requestClass) {
                return $capabilityClass;
            }
        }
        return null;
    }
}
