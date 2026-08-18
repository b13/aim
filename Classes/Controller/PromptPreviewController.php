<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Controller;

use B13\Aim\Backend\Button\RawHtmlButton;
use B13\Aim\Backend\PageTreeResolver;
use B13\Aim\Backend\SortUrlBuilder;
use B13\Aim\Domain\Repository\PagePromptFragmentDemand;
use B13\Aim\Domain\Repository\PagePromptFragmentRepository;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Pagination\DemandedArrayPaginator;
use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Prompt\PromptPreviewService;
use B13\Aim\Service\PageContentExtractor;
use B13\Aim\Service\VoiceCalibrationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\Action\ShortcutButton;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Lists `pages` that have at least one tx_aim_prompt_fragment child,
 * scoped to what the current backend user is actually allowed to see.
 *
 * Filterable by capability, free-text searchable across prompt/examples/fragment
 * title, and paginated.
 */
#[AsController]
class PromptPreviewController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PromptPreviewService $previewService,
        private readonly ProviderConfigurationRepository $configurationRepository,
        private readonly PagePromptFragmentRepository $pagePromptFragmentRepository,
        private readonly PageTreeResolver $pageTreeResolver,
        private readonly SortUrlBuilder $sortUrlBuilder,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
        private readonly VoiceCalibrationService $voiceCalibrationService,
        private readonly PageContentExtractor $pageContentExtractor,
    ) {}

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        if (method_exists($view, 'makeDocHeaderModuleMenu')) {
            $view->makeDocHeaderModuleMenu();
        }
        $languageService = $this->getLanguageService();
        $view->setTitle($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.promptPreview.title'));

        $currentUrl = (string)$request->getAttribute('normalizedParams')->getRequestUri();
        $selectedPageId = (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
        $requestCarriedExplicitPageId = array_key_exists('id', $request->getQueryParams())
            || array_key_exists('id', (array)($request->getParsedBody() ?? []));
        $backendUser = $this->getBackendUser();

        $createPromptUrl = $this->resolveCreatePromptUrl($backendUser, $selectedPageId, $currentUrl);
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $this->addCreatePromptButton($buttonBar, $createPromptUrl);
        $this->addGeneralPreviewButton($buttonBar, $languageService, $selectedPageId);
        $this->addCalibrateVoiceButton($buttonBar, $languageService);
        $shortcutButton = GeneralUtility::makeInstance(ShortcutButton::class)
            ->setRouteIdentifier('aim_prompt_preview')
            ->setDisplayName($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.promptPreview.title'));
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT, 3);

        $scopeOptions = [];
        $scopeLabelsByValue = [];
        foreach (PromptFragmentScope::cases() as $scope) {
            $label = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.' . $scope->value);
            $scopeOptions[] = ['value' => $scope->value, 'label' => $label];
            $scopeLabelsByValue[$scope->value] = $label;
        }

        $demand = PagePromptFragmentDemand::fromRequest($request);
        $accessiblePageIds = $this->pageTreeResolver->resolveAccessiblePageIds($backendUser);
        $hasAnyAccessiblePages = $accessiblePageIds === null || $accessiblePageIds !== [];

        if ($selectedPageId > 0) {
            $subtreePageIds = $this->pageTreeResolver->resolveSubtree($selectedPageId);
            $accessiblePageIds = $accessiblePageIds === null
                ? $subtreePageIds
                : array_values(array_intersect($accessiblePageIds, $subtreePageIds));
        }

        $pageRows = $this->pagePromptFragmentRepository->findByDemand($demand, $accessiblePageIds);
        $totalCount = $this->pagePromptFragmentRepository->countByDemand($demand, $accessiblePageIds);
        $pageRows = $this->pagePromptFragmentRepository->enrichWithFragmentSummary($pageRows);
        $pageRows = array_map(static function (array $row) use ($backendUser, $scopeLabelsByValue): array {
            $row['canEdit'] = $backendUser->doesUserHaveAccess($row, Permission::PAGE_EDIT);
            $row['scopeLabels'] = array_map(
                static fn(string $scopeValue): string => $scopeLabelsByValue[$scopeValue] ?? $scopeValue,
                $row['scopes'],
            );
            return $row;
        }, $pageRows);

        $paginator = new DemandedArrayPaginator($pageRows, $demand->getPage(), $demand->getLimit(), $totalCount);
        $pagination = new SimplePagination($paginator);

        $treeAwareParameters = ['id' => $selectedPageId];
        $paginationBaseUrl = (string)$this->uriBuilder->buildUriFromRoute(
            'aim_prompt_preview',
            array_merge($demand->getParameters(), $treeAwareParameters),
        );

        $selectedPageTitle = null;
        if ($selectedPageId > 0) {
            $selectedPageRow = BackendUtility::getRecord('pages', $selectedPageId);
            $selectedPageTitle = $selectedPageRow['title'] ?? null;
        }

        return $view->assignMultiple([
            'demand' => $demand,
            'scopeOptions' => $scopeOptions,
            'providerConfigurations' => $this->configurationRepository->findAllRawSorted(true),
            'paginator' => $paginator,
            'pagination' => $pagination,
            'paginationBaseUrl' => $paginationBaseUrl,
            'currentUrl' => $currentUrl,
            'selectedPageId' => $selectedPageId,
            'selectedPageTitle' => $selectedPageTitle,
            'createPromptUrl' => $createPromptUrl,
            'sortUrls' => $this->sortUrlBuilder->build($demand, 'aim_prompt_preview', $treeAwareParameters),
            'hasAnyAccessiblePages' => $hasAnyAccessiblePages,
            'showDebugInformation' => $backendUser->shallDisplayDebugInformation(),
            'requestCarriedExplicitPageId' => $requestCarriedExplicitPageId,
        ])->renderResponse('Aim/PromptPreview');
    }

    /**
     * The "New prompt" columnsOnly-restricted edit link
     *
     * Returns null (rather than a URL that would just fail) when no page is
     * selected, the selected page doesn't exist, or the user lacks
     * PAGE_EDIT on it.
     */
    private function resolveCreatePromptUrl(BackendUserAuthentication $backendUser, int $selectedPageId, string $currentUrl): ?string
    {
        if ($selectedPageId <= 0) {
            return null;
        }
        $pageRow = BackendUtility::getRecord('pages', $selectedPageId);
        if ($pageRow === null || !$backendUser->doesUserHaveAccess($pageRow, Permission::PAGE_EDIT)) {
            return null;
        }

        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['pages' => [$selectedPageId => 'edit']],
            'columnsOnly' => ['pages' => ['tx_aim_prompt_fragments', 'tx_aim_disable_inherited_fragments']],
            'returnUrl' => $currentUrl,
        ]);
    }

    private function addCreatePromptButton(ButtonBar $buttonBar, ?string $createPromptUrl): void
    {
        if ($createPromptUrl === null) {
            return;
        }
        $createButton = GeneralUtility::makeInstance(LinkButton::class)
            ->setHref($createPromptUrl)
            ->setShowLabelText(true)
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:promptPreview.createPrompt'))
            ->setIcon($this->iconFactory->getIcon('actions-plus', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL));
        $buttonBar->addButton($createButton, ButtonBar::BUTTON_POSITION_LEFT, 1);
    }

    /**
     * Always-available "Prompt Preview" doc-header action. Opens the same
     * modal breakdown as the per-row "Preview" actions (via the
     * already-registered <aim-prompt-preview-toggle> custom element).
     *
     * Context-aware: targets the tree-selected page's own <template id="pp-panel-selected">
     * when one is selected, and falls back to the page-less <template id="pp-panel-general">
     * Global fallback/user/registry/ addendum layers only, when no page is selected.
     */
    private function addGeneralPreviewButton(ButtonBar $buttonBar, LanguageService $languageService, int $selectedPageId): void
    {
        $targetId = $selectedPageId > 0 ? 'pp-panel-selected' : 'pp-panel-general';
        $label = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:promptPreview.previewGeneral');
        $icon = $this->iconFactory->getIcon('actions-message-dots', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL);
        $generalPreviewButton = GeneralUtility::makeInstance(RawHtmlButton::class)
            ->setHtml(
                '<aim-prompt-preview-toggle target="' . htmlspecialchars($targetId) . '" class="btn btn-sm" title="' . htmlspecialchars($label) . '">'
                . $icon->render() . htmlspecialchars($label)
                . '</aim-prompt-preview-toggle>'
            );
        $buttonBar->addButton($generalPreviewButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
    }

    /**
     * Always-available "Calibrate Voice" doc-header action. Opens a modal
     * (via <aim-calibrate-voice-trigger>) where an editor pastes a sample
     * of real, on-brand copy and gets back an AI-derived tone-of-voice
     * instruction + illustrative Q/A examples, ready to paste into a
     * fragment's prompt/examples fields.
     */
    private function addCalibrateVoiceButton(ButtonBar $buttonBar, LanguageService $languageService): void
    {
        $translate = static fn(string $key): string => $languageService->sL(
            'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:promptPreview.calibrateVoice.' . $key
        );

        $dataAttributes = [
            'url' => (string)$this->uriBuilder->buildUriFromRoute('ajax_aim_calibrate_voice'),
            'modal-title' => $translate('modalTitle'),
            'data-has-provider' => $this->voiceCalibrationService->isAvailable() ? '1' : '0',
            'data-intro' => $translate('intro'),
            'data-placeholder' => $translate('placeholder'),
            'data-analyze-label' => $translate('analyze'),
            'data-loading-label' => $translate('analyzing'),
            'data-tone-label' => $translate('toneLabel'),
            'data-examples-label' => $translate('examplesLabel'),
            'data-copy-label' => $translate('copy'),
            'data-copied-label' => $translate('copied'),
            'data-error-generic' => $translate('errorGeneric'),
            'data-no-provider-message' => $translate('noProvider.message'),
            'data-no-provider-link-label' => $translate('noProvider.linkLabel'),
            'data-providers-url' => (string)$this->uriBuilder->buildUriFromRoute('aim_providers'),
            'data-browse-url' => (string)$this->uriBuilder->buildUriFromRoute('wizard_element_browser', [
                'mode' => 'db',
                'allowedTypes' => 'pages',
                'useEvents' => 1,
                'fieldReference' => 'aim-calibrate-voice-pages',
            ]),
            'data-extract-url' => (string)$this->uriBuilder->buildUriFromRoute('ajax_aim_extract_page_content'),
            'data-select-pages-label' => $translate('selectPages'),
            'data-select-pages-title' => $translate('selectPagesModalTitle'),
            'data-pages-extracting-label' => $translate('pagesExtracting'),
            'data-pages-inserted-label' => $translate('pagesInserted'),
            'data-pages-no-content-label' => $translate('pagesNoContent'),
            'data-source-rendered-label' => $translate('sourceRendered'),
            'data-source-fallback-label' => $translate('sourceFallback'),
        ];

        $attributeString = implode(' ', array_map(
            static fn(string $name, string $value): string => $name . '="' . htmlspecialchars($value) . '"',
            array_keys($dataAttributes),
            $dataAttributes,
        ));
        $label = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:promptPreview.calibrateVoice');
        $icon = $this->iconFactory->getIcon('actions-wand-sparkles', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL);

        $calibrateButton = GeneralUtility::makeInstance(RawHtmlButton::class)
            ->setHtml(
                '<aim-calibrate-voice-trigger ' . $attributeString . '>'
                . '<button type="button" class="btn btn-sm" title="' . htmlspecialchars($label) . '">'
                . $icon->render() . htmlspecialchars($label)
                . '</button>'
                . '</aim-calibrate-voice-trigger>'
            );
        $buttonBar->addButton($calibrateButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
    }

    /**
     * AJAX preview endpoint for the per-row "compose & inspect" action.
     */
    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $rawPageId = (string)($body['pageId'] ?? '');
        if ($rawPageId !== '' && !ctype_digit($rawPageId)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid page id']);
        }
        $pageId = $rawPageId === '' ? null : (int)$rawPageId;
        if ($pageId !== null && $pageId !== 0) {
            $pageRow = BackendUtility::getRecord('pages', $pageId);
            if ($pageRow === null) {
                return new JsonResponse(['ok' => false, 'message' => 'Page not found']);
            }
            $accessiblePageIds = $this->pageTreeResolver->resolveAccessiblePageIds($this->getBackendUser());
            if ($accessiblePageIds !== null && !in_array($pageId, $accessiblePageIds, true)) {
                return new JsonResponse(['ok' => false, 'message' => 'Page not found']);
            }
        }

        $scope = PromptFragmentScope::tryFrom((string)($body['scope'] ?? ''));
        if ($scope === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Unknown or missing scope']);
        }

        $rawProviderUid = (string)($body['providerConfigurationUid'] ?? '');
        $providerConfigurationUid = $rawProviderUid === '' ? null : (int)$rawProviderUid;
        if ($providerConfigurationUid !== null) {
            // Checked against the enabled set specifically (not findByUid()
            // against every configuration). The dropdown this value comes
            // from only ever offers enabled configurations, and the backend
            // must not honor a uid the UI never actually presented.
            $enabledIds = array_map(
                static fn(array $row): int => (int)$row['uid'],
                $this->configurationRepository->findAllRawSorted(true),
            );
            if (!in_array($providerConfigurationUid, $enabledIds, true)) {
                return new JsonResponse(['ok' => false, 'message' => 'Provider configuration not available']);
            }
        }

        $result = $this->previewService->preview($pageId, $scope, $providerConfigurationUid);

        return new JsonResponse(['ok' => true, ...$result]);
    }

    /**
     * AJAX endpoint for the "Calibrate Voice" doc-header action.
     */
    public function calibrateVoiceAction(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($this->voiceCalibrationService->calibrate(
            (string)(($request->getParsedBody() ?? [])['text'] ?? '')
        ));
    }

    /**
     * AJAX endpoint feeding the calibration modal's page picker: extracts
     * tt_content text for one or more posted page uids.
     */
    public function extractPageContentAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $pageUids = array_values(array_unique(array_map(
            static fn(mixed $uid): int => (int)$uid,
            is_array($body['pageUids'] ?? null) ? $body['pageUids'] : [],
        )));
        $pageUids = array_values(array_filter($pageUids, static fn(int $uid): bool => $uid > 0));
        if ($pageUids === []) {
            return new JsonResponse(['ok' => false, 'message' => 'No page selected']);
        }

        $accessiblePageIds = $this->pageTreeResolver->resolveAccessiblePageIds($this->getBackendUser());
        if ($accessiblePageIds !== null) {
            $pageUids = array_values(array_intersect($pageUids, $accessiblePageIds));
        }
        if ($pageUids === []) {
            return new JsonResponse(['ok' => false, 'message' => 'Page not found']);
        }

        $result = $this->pageContentExtractor->extractTextWithSources($pageUids);
        if (trim($result['text']) === '') {
            return new JsonResponse(['ok' => false, 'message' => 'No text content found on the selected page(s)']);
        }

        return new JsonResponse(['ok' => true, 'text' => $result['text'], 'sources' => $result['sources']]);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
