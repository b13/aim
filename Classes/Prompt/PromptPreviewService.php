<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Prompt;

use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Composes the same layers TonePromptCompositionMiddleware/ProviderAddendumMiddleware
 * would, for a given page/scope/provider, WITHOUT dispatching to a real
 * AiProviderInterface. A read-only "what would actually be sent" preview
 * for the AiM backend module, so an editor can inspect a composition before
 * spending a real, billable API call to find out what it looks like.
 *
 * Mirrors both middlewares' composition order exactly:
 *   1. Page tone (PagePromptResolver) when a page is given, otherwise the
 *      same global ExtensionConfiguration fallback TonePromptCompositionMiddleware
 *      uses for page-less requests (e.g. sys_file_metadata).
 *   2. User TSconfig fragments (UserPromptFragmentResolver).
 *   3. Code-registered fragments (PromptFragmentRegistry).
 *   4. Provider addendum. Only present when a provider configuration was
 *      selected, and suppressed if it exactly duplicates a part already
 *      collected in layers 1-3, exactly mirroring
 *      ProviderAddendumMiddleware's own duplicate-suppression. Without this,
 *      the preview would show an addendum layer whose content then
 *      mysteriously disappears from `composed` once deduped.
 *
 * The caller's own task instruction (layer 1 of the real pipeline) doesn't
 * exist yet at preview time. It's a per-call argument, not something this
 * service can know in advance, so it's intentionally not represented here;
 * the consuming UI shows a fixed explanatory note instead.
 *
 * Callers are expected to validate that a given page id / provider
 * configuration uid actually exists before calling this. A non-existent
 * page id is indistinguishable from "page exists but has no tone" once it
 * reaches PagePromptResolver::resolve() (it swallows lookup failures
 * internally), and a non-existent provider configuration uid is handled
 * gracefully here (contributes no addendum) rather than raising an error,
 * so a caller that skips validation degrades quietly instead of crashing.
 */
class PromptPreviewService
{
    public function __construct(
        private readonly PagePromptResolver $pagePromptResolver,
        private readonly UserPromptFragmentResolver $userFragmentResolver,
        private readonly PromptFragmentRegistry $fragmentRegistry,
        private readonly ProviderConfigurationRepository $configurationRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @return array{
     *     layers: list<array{labelKey: string, parts: list<string>, characterCount: int}>,
     *     composed: string,
     *     totalCharacterCount: int,
     *     approximateTokenCount: int,
     * }
     */
    public function preview(?int $pageId, PromptFragmentScope $scope, ?int $providerConfigurationUid = null): array
    {
        $collectedParts = [];
        $layers = [];

        $toneKey = $pageId !== null ? 'pageTone' : 'globalFallback';
        $toneParts = PromptComposer::dedupe([$this->resolveTone($pageId, $scope)]);
        $layers[] = $this->buildLayer($toneKey, $toneParts);
        array_push($collectedParts, ...$toneParts);

        $userParts = PromptComposer::dedupe($this->userFragmentResolver->getFragments($scope));
        $layers[] = $this->buildLayer('userFragments', $userParts);
        array_push($collectedParts, ...$userParts);

        $registryParts = PromptComposer::dedupe($this->fragmentRegistry->getFragments($scope));
        $layers[] = $this->buildLayer('registryFragments', $registryParts);
        array_push($collectedParts, ...$registryParts);

        if ($providerConfigurationUid !== null) {
            $addendumParts = $this->resolveAddendum($providerConfigurationUid, $collectedParts);
            $layers[] = $this->buildLayer('providerAddendum', $addendumParts);
            array_push($collectedParts, ...$addendumParts);
        }

        $composed = PromptComposer::compose($collectedParts);

        return [
            'layers' => $layers,
            'composed' => $composed,
            'totalCharacterCount' => strlen($composed),
            'approximateTokenCount' => (int)ceil(strlen($composed) / 4),
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

    /**
     * @param list<string> $collectedPartsSoFar
     * @return list<string>
     */
    private function resolveAddendum(int $providerConfigurationUid, array $collectedPartsSoFar): array
    {
        $configuration = $this->configurationRepository->findByUid($providerConfigurationUid);
        if ($configuration === null) {
            return [];
        }

        $addendum = trim($configuration->systemPromptAddition);
        if (PromptComposer::isRedundant($addendum, $collectedPartsSoFar)) {
            return [];
        }

        return [$addendum];
    }

    /**
     * @param list<string> $parts
     * @return array{labelKey: string, parts: list<string>, characterCount: int}
     */
    private function buildLayer(string $labelKey, array $parts): array
    {
        return [
            'labelKey' => $labelKey,
            'parts' => $parts,
            'characterCount' => array_sum(array_map('strlen', $parts)),
        ];
    }
}
