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
use B13\Aim\Governance\ConfigurationAccess;
use Psr\Log\LoggerInterface;
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
        private readonly ConfigurationAccess $configurationAccess,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     layers: list<array{labelKey: string, parts: list<string>, characterCount: int, unavailable: bool}>,
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
        $tone = $this->resolveTone($pageId, $scope);
        $toneParts = PromptComposer::dedupe([$tone['tone']]);
        $layers[] = $this->buildLayer($toneKey, $toneParts, $tone['failed']);
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

    /**
     * Unlike TonePromptCompositionMiddleware, which drops the tone and answers
     * the request anyway, this is a diagnostic view: someone asked to see
     * exactly what the model receives. Returning an empty tone here would show
     * a prompt without it and read as "none is configured", which is a wrong
     * answer presented as a correct one. So a failure is carried out to the
     * caller and shown as its own layer instead.
     *
     * @return array{tone: string, failed: bool}
     */
    private function resolveTone(?int $pageId, PromptFragmentScope $scope): array
    {
        if ($pageId !== null) {
            try {
                return ['tone' => $this->pagePromptResolver->resolve($pageId, $scope) ?? '', 'failed' => false];
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Could not resolve the tone of voice for page {page} while building a preview. {reason}',
                    ['page' => $pageId, 'reason' => $e->getMessage(), 'exception' => $e],
                );

                return ['tone' => '', 'failed' => true];
            }
        }

        try {
            return ['tone' => (string)$this->extensionConfiguration->get('aim', 'defaultSystemPrompt'), 'failed' => false];
        } catch (\Throwable) {
            return ['tone' => '', 'failed' => false];
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

        if (!$this->configurationAccess->isAccessibleByCurrentUser($configuration)) {
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
    /**
     * @param list<string> $parts
     * @return array{labelKey: string, parts: list<string>, characterCount: int, unavailable: bool}
     */
    private function buildLayer(string $labelKey, array $parts, bool $unavailable = false): array
    {
        return [
            'labelKey' => $labelKey,
            'parts' => $parts,
            'characterCount' => array_sum(array_map('strlen', $parts)),
            'unavailable' => $unavailable,
        ];
    }
}
