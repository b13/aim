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
use B13\Aim\Prompt\PagePromptResolver;
use B13\Aim\Prompt\PromptComposer;
use B13\Aim\Prompt\PromptFragmentRegistry;
use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Prompt\PromptOverrideNormalizer;
use B13\Aim\Prompt\UserPromptFragmentResolver;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ImageGenerationRequest;
use B13\Aim\Request\SupportsPageContextInterface;
use B13\Aim\Request\SupportsSystemPromptInterface;
use B13\Aim\Response\TextResponse;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Composes the caller's own prompt with tone-of-voice content. Everything
 * except the provider-specific addendum, which is a separate, later
 * middleware (ProviderAddendumMiddleware). Together they replace what used
 * to be a single SystemPromptResolutionMiddleware:
 *
 *   1. The caller's own domain-specific prompt (task definition, e.g.
 *      descriptive_images' "generate alt text..." instruction, or the
 *      creative prompt for image generation), unchanged.
 *   2. The resolved tone-of-voice: page-tree fragments (PagePromptResolver,
 *      tx_aim_prompt_fragment + Page TSconfig, additive per-fragment via
 *      inherit_to_subpages / TSconfig's own cascade+clear) when the request
 *      carries a pageId, otherwise the global Extension Configuration
 *      default (files have no meaningful page-tree position, see
 *      PagePromptResolver's docblock).
 *   3. User/Group-TSconfig-assigned fragments (UserPromptFragmentResolver),
 *      applied whenever a BE_USER exists, regardless of pageId, since this
 *      is a person/role axis rather than a page axis.
 *   4. Code-registered fragments (PromptFragmentRegistry) matching the
 *      request's scope, applied regardless of page context, since these
 *      represent extension-level policy (e.g. a watermark rule), not
 *      page-specific tone.
 *
 * For chat-shaped requests (SupportsSystemPromptInterface) these layers are
 * composed into the system prompt. Image generation has no system-role
 * channel, so they're spliced into `prompt` instead, after the caller's own
 * creative prompt. EmbeddingRequest gets neither, no textual output to steer.
 *
 * Priority 80: deliberately ABOVE SmartRoutingMiddleware (75) and
 * CapabilityValidationMiddleware (50). Unlike the addendum (which needs the
 * final post-rerouting provider), tone/user/registry fragments don't
 * depend on provider resolution at all. Running this early means
 * SmartRoutingMiddleware's complexity classification (and therefore its
 * "downgrade to a cheaper model" decision) sees the real, fragment-inflated
 * prompt instead of just the caller's bare task text, closing a routing
 * blind spot the original single-middleware design had (a short, simple
 * task prompt could get waved through to a weaker model even though the
 * actual outbound payload, once tone-of-voice fragments were added, was
 * large and instruction-heavy).
 *
 * Any request implementing SupportsPageContextInterface can opt out of all
 * of this (and the addendum middleware) entirely via
 * isAutomaticPromptCompositionDisabled(). getSystemPromptOverride() lets a
 * caller supply fragment(s) that totally replace this middleware's
 * resolution (still composed with the caller's own layer-1 prompt) and
 * also suppresses ProviderAddendumMiddleware, since "total override"
 * means total, not "everything except the bit AiM insists on".
 */
#[AsAiMiddleware(priority: 80)]
final class TonePromptCompositionMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly PagePromptResolver $pagePromptResolver,
        private readonly UserPromptFragmentResolver $userFragmentResolver,
        private readonly PromptFragmentRegistry $fragmentRegistry,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        if ($request instanceof SupportsPageContextInterface && $request->isAutomaticPromptCompositionDisabled()) {
            return $next->handle($request, $provider, $configuration);
        }

        $override = $request instanceof SupportsPageContextInterface
            ? PromptOverrideNormalizer::normalize($request->getSystemPromptOverride())
            : [];
        $next->context->promptOverrideApplied = $override !== [];

        if ($request instanceof SupportsSystemPromptInterface) {
            $parts = $override !== []
                ? [$request->getSystemPrompt(), ...$override]
                : $this->automaticParts($request, $request->getPromptFragmentScope());
            $deduped = PromptComposer::dedupe($parts);
            $next->context->composedPromptParts = $deduped;
            $composed = implode("\n\n", $deduped);
            if ($composed !== $request->getSystemPrompt()) {
                $request = $request->withSystemPrompt($composed);
            }
        } elseif ($request instanceof ImageGenerationRequest) {
            $parts = $override !== []
                ? $override
                : $this->automaticExtraParts($request, $request->getPromptFragmentScope());
            $deduped = PromptComposer::dedupe($parts);
            $next->context->composedPromptParts = $deduped;
            $extra = implode("\n\n", $deduped);
            if ($extra !== '') {
                $request = $request->withPrompt(PromptComposer::compose([$request->prompt, $extra]));
            }
        }

        return $next->handle($request, $provider, $configuration);
    }

    private function automaticParts(SupportsSystemPromptInterface $request, PromptFragmentScope $scope): array
    {
        return [
            $request->getSystemPrompt(),
            $this->resolveTone($request->getPageId(), $scope),
            ...$this->userFragmentResolver->getFragments($scope),
            ...$this->fragmentRegistry->getFragments($scope),
        ];
    }

    private function automaticExtraParts(ImageGenerationRequest $request, PromptFragmentScope $scope): array
    {
        return [
            $this->resolveTone($request->getPageId(), $scope),
            ...$this->userFragmentResolver->getFragments($scope),
            ...$this->fragmentRegistry->getFragments($scope),
        ];
    }

    private function resolveTone(?int $pageId, PromptFragmentScope $scope): string
    {
        if ($pageId !== null) {
            return $this->pagePromptResolver->resolve($pageId, $scope) ?? '';
        }

        try {
            return (string)$this->extensionConfiguration->get('aim', 'defaultSystemPrompt');
        } catch (\Throwable) {
            return '';
        }
    }
}
