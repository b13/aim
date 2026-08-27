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
 * Backs the merged aim_prompt_management module: overviewAction() renders
 * either of its two sub-views, switched via a `view` request parameter.
 *
 * - Pages (default): lists `pages` that have at least one
 *   tx_aim_prompt_fragment assigned via a tx_aim_page_prompt_fragment
 *   record, scoped to what the current backend user is actually allowed to
 *   see, filterable by capability, free-text searchable across
 *   prompt/examples/fragment title, and paginated.
 * - Library (renderLibraryView()): browses the reusable fragment pool
 *   itself, independent of any single page's assignments.
 *
 * Also owns previewAction(), calibrateVoiceAction() and
 * extractPageContentAction() - AJAX endpoints reached directly, each
 * with its own access check since raw AJAX routes bypass TYPO3's
 * module-route access validation.
 */
#[AsController]
class PromptManagementController
{
    /**
     * Caps the Library view's inline "used on N pages" list: a widely
     * inherited fragment could otherwise render hundreds of list items,
     * each costing its own BackendUtility lookup for the edit link/path.
     */
    private const MAX_USAGE_PAGES_SHOWN = 25;

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
        $view->setTitle($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.promptManagement.title'));

        $currentUrl = (string)$request->getAttribute('normalizedParams')->getRequestUri();
        $selectedPageId = (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
        $requestCarriedExplicitPageId = array_key_exists('id', $request->getQueryParams())
            || array_key_exists('id', (array)($request->getParsedBody() ?? []));
        $backendUser = $this->getBackendUser();
        // The module itself is only gated on 'access' => 'user' (which
        // module menu to see at all), not on whether the granting group
        // also intended read access to fragment CONTENT specifically.
        $hasFragmentReadAccess = $backendUser->check('tables_select', 'tx_aim_prompt_fragment');
        $moduleData = $request->getAttribute('moduleData');
        $activeView = PromptManagementView::tryFrom((string)$moduleData?->get('view')) ?? PromptManagementView::Pages;

        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $this->addGeneralPreviewButton($buttonBar, $languageService, $selectedPageId);
        $this->addCalibrateVoiceButton($buttonBar, $languageService);

        $viewBoxes = $this->buildViewBoxes($languageService, $activeView, $selectedPageId);
        $shortcutButton = GeneralUtility::makeInstance(ShortcutButton::class)
            ->setRouteIdentifier('aim_prompt_management')
            // 'view' is always stated explicitly here, never omitted for the
            // Pages case: a bookmark is meant to reopen exactly this view,
            // regardless of whichever view this user's moduleData happens to
            // have persisted by the time they click it back open.
            ->setArguments(array_filter([
                'view' => $activeView->value,
                'id' => $selectedPageId > 0 ? $selectedPageId : null,
            ]))
            ->setDisplayName(
                $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.promptManagement.title')
                . ' – ' . $viewBoxes[$activeView->value]['headline']
            );
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT, 3);

        [$scopeOptions, $scopeLabelsByValue] = $this->buildScopeOptions($languageService);

        if ($activeView === PromptManagementView::Library) {
            return $this->renderLibraryView(new PromptManagementViewContext(
                $view,
                $request,
                $backendUser,
                $buttonBar,
                $currentUrl,
                $selectedPageId,
                $languageService,
                $scopeOptions,
                $scopeLabelsByValue,
                $viewBoxes,
                $hasFragmentReadAccess,
                $requestCarriedExplicitPageId,
            ));
        }

        // Gated on $hasFragmentReadAccess, not just PAGE_EDIT, since the link
        // opens the page edit form's own `fragment` picker, which lets the
        // user browse/reuse titles from the whole accessible fragment library.
        $createPromptUrl = $hasFragmentReadAccess
            ? $this->resolveCreatePromptUrl($backendUser, $selectedPageId, $currentUrl)
            : null;
        $this->addCreatePromptButton($buttonBar, $createPromptUrl);

        $demand = PagePromptFragmentDemand::fromRequest($request);
        $accessiblePageIds = $this->pageTreeResolver->resolveAccessiblePageIds($backendUser);
        $hasAnyAccessiblePages = $accessiblePageIds !== [];

        if ($selectedPageId > 0) {
            $subtreePageIds = $this->pageTreeResolver->resolveSubtree($selectedPageId);
            $accessiblePageIds = $accessiblePageIds === null
                ? $subtreePageIds
                : array_values(array_intersect($accessiblePageIds, $subtreePageIds));
        }

        if ($hasFragmentReadAccess) {
            $hasFragmentModifyAccess = $backendUser->check('tables_modify', 'tx_aim_prompt_fragment');
            $pageRows = $this->pagePromptFragmentRepository->findByDemand($demand, $accessiblePageIds);
            $totalCount = $this->pagePromptFragmentRepository->countByDemand($demand, $accessiblePageIds);
            $pageRows = $this->pagePromptFragmentRepository->enrichWithFragmentSummary($pageRows);
            $pageEditAccessCache = [];
            $pageRows = array_map(function (array $row) use ($backendUser, $scopeLabelsByValue, $hasFragmentModifyAccess, &$pageEditAccessCache): array {
                $row['canEdit'] = $backendUser->doesUserHaveAccess($row, Permission::PAGE_EDIT);
                $row['scopeLabels'] = array_map(
                    static fn(string $scopeValue): string => $scopeLabelsByValue[$scopeValue] ?? $scopeValue,
                    $row['scopes'],
                );
                $row['fragments'] = array_map(
                    function (array $fragment) use ($scopeLabelsByValue, $backendUser, $hasFragmentModifyAccess, &$pageEditAccessCache): array {
                        $fragment['scopeLabels'] = array_map(
                            static fn(string $scopeValue): string => $scopeLabelsByValue[$scopeValue] ?? $scopeValue,
                            PromptFragmentScope::parseList($fragment['scope']),
                        );
                        $fragment['canEdit'] = $this->canEditFragmentRow($hasFragmentModifyAccess, $fragment['pid'], $backendUser, $pageEditAccessCache);
                        return $fragment;
                    },
                    $row['fragments'],
                );
                return $row;
            }, $pageRows);
        } else {
            $pageRows = [];
            $totalCount = 0;
        }

        $paginator = new DemandedArrayPaginator($pageRows, $demand->getPage(), $demand->getLimit(), $totalCount);
        $pagination = new SimplePagination($paginator);

        $treeAwareParameters = ['view' => $activeView->value, 'id' => $selectedPageId];
        $paginationBaseUrl = (string)$this->uriBuilder->buildUriFromRoute(
            'aim_prompt_management',
            $this->sortUrlBuilder->buildFilterParameters($demand, $treeAwareParameters),
        );

        $selectedPageTitle = null;
        if ($selectedPageId > 0) {
            $selectedPageRow = BackendUtility::getRecord('pages', $selectedPageId);
            $selectedPageTitle = $selectedPageRow['title'] ?? null;
        }

        return $view->assignMultiple([
            'activeView' => $activeView->value,
            'viewBoxes' => $viewBoxes,
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
            'sortUrls' => $this->sortUrlBuilder->build($demand, 'aim_prompt_management', $treeAwareParameters),
            'hasAnyAccessiblePages' => $hasAnyAccessiblePages,
            'hasFragmentReadAccess' => $hasFragmentReadAccess,
            'showDebugInformation' => $backendUser->shallDisplayDebugInformation(),
            'requestCarriedExplicitPageId' => $requestCarriedExplicitPageId,
        ])->renderResponse('Aim/PromptPreview');
    }

    /**
     * @return array{0: list<array{value: string, label: string}>, 1: array<string, string>}
     */
    private function buildScopeOptions(LanguageService $languageService): array
    {
        $scopeOptions = [];
        $scopeLabelsByValue = [];
        foreach (PromptFragmentScope::cases() as $scope) {
            $label = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.' . $scope->value);
            $scopeOptions[] = ['value' => $scope->value, 'label' => $label];
            $scopeLabelsByValue[$scope->value] = $label;
        }
        return [$scopeOptions, $scopeLabelsByValue];
    }

    /**
     * The two clickable sub-action boxes ("Pages"/"Library") the module's
     * own doc-header-less box switcher renders (see PromptPreview.html):
     * plain, bookmarkable full-navigation links carrying the tree-selected
     * page (`id`), never the listing's own demand/page params. Box navigation
     * and in-listing filtering/pagination are independent axes of state,
     * not one combined into the other.
     *
     * @return array<string, array{view: string, url: string, active: bool, headline: string, description: string}>
     */
    private function buildViewBoxes(LanguageService $languageService, PromptManagementView $activeView, int $selectedPageId): array
    {
        $translate = static fn(string $key): string => $languageService->sL(
            'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:promptPreview.tabs.' . $key
        );
        return [
            PromptManagementView::Pages->value => [
                'view' => PromptManagementView::Pages->value,
                'url' => (string)$this->uriBuilder->buildUriFromRoute('aim_prompt_management', ['view' => PromptManagementView::Pages->value, 'id' => $selectedPageId]),
                'active' => $activeView === PromptManagementView::Pages,
                'headline' => $translate('pages.headline'),
                'description' => $translate('pages.description'),
            ],
            PromptManagementView::Library->value => [
                'view' => PromptManagementView::Library->value,
                'url' => (string)$this->uriBuilder->buildUriFromRoute('aim_prompt_management', ['view' => PromptManagementView::Library->value, 'id' => $selectedPageId]),
                'active' => $activeView === PromptManagementView::Library,
                'headline' => $translate('library.headline'),
                'description' => $translate('library.description'),
            ],
        ];
    }

    /**
     * The Library sub-action: every reusable fragment, for browsing/reuse
     * rather than per-page management.
     *
     * Honors the shared page tree the same way the Pages sub-action does:
     * selecting a page scopes the listing to fragments actually ASSIGNED
     * somewhere in that subtree, not to fragments merely STORED there.
     * The fragment's own storage pid is already spoken for by the
     * $accessiblePageIds access-control check  (pid=0 global fragments
     * always pass it; a restricted user's own accessible pages otherwise).
     * Global (pid=0) fragments are unaffected, they are always shown,
     * independent of tree selection.
     *
     * Expanding "N page(s)" reveals one table row per page, each offering
     * two actions: edit that page's own AI tab fields, or open the
     * "compose & inspect" preview. That usage list intentionally
     * still reports EVERY page the user may see the fragment is used on,
     * regardless of the tree selection.
     */
    private function renderLibraryView(PromptManagementViewContext $context): ResponseInterface
    {
        $this->addNewFragmentButton($context->buttonBar, $context->backendUser, $context->currentUrl, $context->languageService, $context->selectedPageId);
        $demand = PagePromptFragmentDemand::fromRequest($context->request);
        $accessiblePageIds = $this->pageTreeResolver->resolveAccessiblePageIds($context->backendUser);
        $usagePageIds = null;
        if ($context->selectedPageId > 0) {
            $subtreePageIds = $this->pageTreeResolver->resolveSubtree($context->selectedPageId);
            $usagePageIds = $accessiblePageIds === null
                ? $subtreePageIds
                : array_values(array_intersect($accessiblePageIds, $subtreePageIds));
        }

        $selectedPageTitle = null;
        if ($context->selectedPageId > 0) {
            $selectedPageRow = BackendUtility::getRecord('pages', $context->selectedPageId);
            $selectedPageTitle = $selectedPageRow['title'] ?? null;
        }

        $usagePagesById = [];
        if ($context->hasFragmentReadAccess) {
            $hasFragmentModifyAccess = $context->backendUser->check('tables_modify', 'tx_aim_prompt_fragment');
            $fragmentRows = $this->pagePromptFragmentRepository->findLibraryByDemand($demand, $accessiblePageIds, $usagePageIds);
            $totalCount = $this->pagePromptFragmentRepository->countLibraryByDemand($demand, $accessiblePageIds, $usagePageIds);
            $fragmentRows = $this->pagePromptFragmentRepository->enrichLibraryWithUsage($fragmentRows, $accessiblePageIds, self::MAX_USAGE_PAGES_SHOWN);
            $editUrlsByPageId = [];
            $pageEditAccessCache = [];
            $fragmentRows = array_map(
                function (array $row) use ($context, $hasFragmentModifyAccess, &$usagePagesById, &$editUrlsByPageId, &$pageEditAccessCache): array {
                    return $this->enrichLibraryRow($row, $context, $hasFragmentModifyAccess, $usagePagesById, $editUrlsByPageId, $pageEditAccessCache);
                },
                $fragmentRows,
            );
        } else {
            $fragmentRows = [];
            $totalCount = 0;
        }

        $paginator = new DemandedArrayPaginator($fragmentRows, $demand->getPage(), $demand->getLimit(), $totalCount);
        $pagination = new SimplePagination($paginator);
        $treeAwareParameters = ['view' => PromptManagementView::Library->value, 'id' => $context->selectedPageId];
        $paginationBaseUrl = (string)$this->uriBuilder->buildUriFromRoute(
            'aim_prompt_management',
            array_merge($this->sortUrlBuilder->buildFilterParameters($demand), $treeAwareParameters),
        );

        return $context->view->assignMultiple([
            'activeView' => PromptManagementView::Library->value,
            'viewBoxes' => $context->viewBoxes,
            'demand' => $demand,
            'scopeOptions' => $context->scopeOptions,
            'providerConfigurations' => $this->configurationRepository->findAllRawSorted(true),
            'paginator' => $paginator,
            'pagination' => $pagination,
            'paginationBaseUrl' => $paginationBaseUrl,
            'currentUrl' => $context->currentUrl,
            'selectedPageId' => $context->selectedPageId,
            'selectedPageTitle' => $selectedPageTitle,
            'requestCarriedExplicitPageId' => $context->requestCarriedExplicitPageId,
            'usagePagePreviewPanels' => array_values($usagePagesById),
            'sortUrls' => $this->sortUrlBuilder->build($demand, 'aim_prompt_management', $treeAwareParameters),
            'showDebugInformation' => $context->backendUser->shallDisplayDebugInformation(),
            'hasFragmentReadAccess' => $context->hasFragmentReadAccess,
            // See overviewAction()'s identical comment: computed per-fragment
            // above (canEditFragmentRow()) rather than one flat table-level
            // flag here.
        ])->renderResponse('Aim/PromptPreview');
    }

    /**
     * Enriches one Library row: scope labels, per-row edit-link gating
     * (canEditFragmentRow()), the isGlobal badge flag, and the
     * usedOnPages/usedOnPagesTruncatedCount pair. $usagePagesById,
     * $editUrlsByPageId and $pageEditAccessCache are shared by reference
     * across every row in the same array_map() call in renderLibraryView(),
     * keyed by page uid, so a page reused by several fragments only gets
     * its preview-panel data, edit url and CONTENT_EDIT check built once.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, string>> $usagePagesById
     * @param array<int, string|null> $editUrlsByPageId
     * @param array<int, bool> $pageEditAccessCache
     * @return array<string, mixed>
     */
    private function enrichLibraryRow(
        array $row,
        PromptManagementViewContext $context,
        bool $hasFragmentModifyAccess,
        array &$usagePagesById,
        array &$editUrlsByPageId,
        array &$pageEditAccessCache,
    ): array {
        $row['scopeLabels'] = array_map(
            static fn(string $scopeValue): string => $context->scopeLabelsByValue[$scopeValue] ?? $scopeValue,
            PromptFragmentScope::parseList((string)$row['scope']),
        );
        $row['canEdit'] = $this->canEditFragmentRow($hasFragmentModifyAccess, (int)$row['pid'], $context->backendUser, $pageEditAccessCache);
        // A global fragment is exempt from the tree-selection usage
        // filter above and so can appear here without actually being
        // assigned anywhere near the selected subtree, flagged so the
        // template can badge it, rather than implying it's specifically
        // used on/near the selected page.
        $row['isGlobal'] = (int)$row['pid'] === 0;
        $usagePages = $row['usedOnPages'];
        $row['usedOnPagesTruncatedCount'] = max(0, (int)$row['usedOnPageCount'] - count($usagePages));
        $row['usedOnPages'] = array_map(
            function (array $usagePage) use ($context, &$usagePagesById, &$editUrlsByPageId): array {
                $pageUid = (int)$usagePage['uid'];
                if (!isset($usagePagesById[$pageUid])) {
                    $usagePagesById[$pageUid] = $this->buildPreviewPanelData($context->languageService, $pageUid, $usagePage['title']);
                }
                if (!array_key_exists($pageUid, $editUrlsByPageId)) {
                    $editUrlsByPageId[$pageUid] = $this->resolvePageFragmentsEditUrl($context->backendUser, $pageUid, $context->currentUrl);
                }
                return [
                    'uid' => $pageUid,
                    'title' => $usagePage['title'],
                    'hidden' => $usagePage['hidden'],
                    'doktype' => $usagePage['doktype'],
                    'is_siteroot' => $usagePage['is_siteroot'],
                    'starttime' => $usagePage['starttime'],
                    'endtime' => $usagePage['endtime'],
                    'nav_hide' => $usagePage['nav_hide'],
                    'fe_group' => $usagePage['fe_group'],
                    'module' => $usagePage['module'],
                    'content_from_pid' => $usagePage['content_from_pid'],
                    'editUrl' => $editUrlsByPageId[$pageUid],
                    'previewTargetId' => 'pp-panel-usage-page-' . $pageUid,
                ];
            },
            $usagePages,
        );
        return $row;
    }

    /**
     * Builds the same set of data-* values every <template> panel in this
     * module carries (see PromptPreview.html's per-row templates), so a
     * <aim-prompt-preview-toggle> can open the "compose & inspect" modal
     * for one specific page uid. Pulled out into its own method because
     * the Library module now needs to build one of these per unique usage
     * page rather than once per table row.
     *
     * @return array<string, string>
     */
    private function buildPreviewPanelData(LanguageService $languageService, int $pageId, string $pageTitle): array
    {
        $translate = static fn(string $key): string => $languageService->sL(
            'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:promptPreview.' . $key
        );

        return [
            'targetId' => 'pp-panel-usage-page-' . $pageId,
            'pageId' => (string)$pageId,
            'modalTitle' => sprintf($translate('modalTitle'), $pageTitle),
            'labelEmpty' => $translate('result.empty'),
            'labelCharacters' => $translate('result.characters.label'),
            'labelTokens' => $translate('result.tokens.label'),
            'labelPageTone' => $translate('layer.pageTone'),
            'labelGlobalFallback' => $translate('layer.globalFallback'),
            'labelUserFragments' => $translate('layer.userFragments'),
            'labelRegistryFragments' => $translate('layer.registryFragments'),
            'labelProviderAddendum' => $translate('layer.providerAddendum'),
        ];
    }

    /**
     * "New fragment" targets whichever pid a fresh fragment there would
     * actually be writable at, so it works for a non-admin editor too, not
     * just for admins:
     *
     * - A page is selected in the tree: target that page's pid, gated by
     *   the exact same tables_modify + CONTENT_EDIT rule canEditFragmentRow()
     *   applies to an existing row at that pid. A sysfolder-stored fragment
     *   there is ordinary DataHandler-writable for any user with those grants.
     * - No page is selected: the only sensible target left is pid=0 (a
     *   true global library entry), which DataHandler only permits an
     *   admin to write, so the button is hidden entirely for a non-admin
     *   in that case, rather than linking to the edit form, failing on save.
     */
    private function addNewFragmentButton(ButtonBar $buttonBar, BackendUserAuthentication $backendUser, string $currentUrl, LanguageService $languageService, int $selectedPageId): void
    {
        $targetPid = $this->resolveNewFragmentTargetPid($backendUser, $selectedPageId);
        if ($targetPid === null) {
            return;
        }
        $newFragmentUrl = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_aim_prompt_fragment' => [$targetPid => 'new']],
            'returnUrl' => $currentUrl,
        ]);
        $newButton = GeneralUtility::makeInstance(LinkButton::class)
            ->setHref($newFragmentUrl)
            ->setShowLabelText(true)
            ->setTitle($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:promptPreview.library.newFragment'))
            ->setIcon($this->iconFactory->getIcon('actions-plus', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL));
        $buttonBar->addButton($newButton, ButtonBar::BUTTON_POSITION_LEFT, 1);
    }

    private function resolveNewFragmentTargetPid(BackendUserAuthentication $backendUser, int $selectedPageId): ?int
    {
        if (!$backendUser->check('tables_modify', 'tx_aim_prompt_fragment')) {
            return null;
        }
        if ($selectedPageId <= 0) {
            return $backendUser->isAdmin() ? 0 : null;
        }
        $pageRow = BackendUtility::getRecord('pages', $selectedPageId);
        if ($pageRow === null || !$backendUser->doesUserHaveAccess($pageRow, Permission::CONTENT_EDIT)) {
            return null;
        }
        return $selectedPageId;
    }

    /**
     * The "New prompt" columnsOnly-restricted edit link
     *
     * Returns null (rather than a URL that would just fail) when no page is
     * selected, the selected page doesn't exist, or user lacks PAGE_EDIT on it.
     */
    private function resolveCreatePromptUrl(BackendUserAuthentication $backendUser, int $selectedPageId, string $currentUrl): ?string
    {
        if ($selectedPageId <= 0) {
            return null;
        }
        return $this->resolvePageFragmentsEditUrl($backendUser, $selectedPageId, $currentUrl);
    }

    private function resolvePageFragmentsEditUrl(BackendUserAuthentication $backendUser, int $pageId, string $currentUrl): ?string
    {
        $pageRow = BackendUtility::getRecord('pages', $pageId);
        if ($pageRow === null || !$backendUser->doesUserHaveAccess($pageRow, Permission::PAGE_EDIT)) {
            return null;
        }

        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['pages' => [$pageId => 'edit']],
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
        if (!$this->getBackendUser()->check('tables_select', 'tx_aim_prompt_fragment')) {
            return new JsonResponse(['ok' => false, 'message' => 'Access denied']);
        }

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
        if (!$this->getBackendUser()->check('modules', 'aim_prompt_management')) {
            return new JsonResponse(['ok' => false, 'message' => 'Access denied'], 403);
        }

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
        if (!$this->getBackendUser()->check('modules', 'aim_prompt_management')) {
            return new JsonResponse(['ok' => false, 'message' => 'Access denied'], 403);
        }

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

    /**
     * Whether a fragment edit link should render for a specific row.
     * $hasTableLevelModifyAccess (tables_modify for tx_aim_prompt_fragment)
     * is necessary but not sufficient on its own, for two independent
     * reasons:
     *
     * - pid<=0 (global library fragment): TCA doesn't set
     *   security.ignoreRootLevelRestriction on this table, so DataHandler
     *   additionally requires the user to be an admin before it will save
     *   ANY record at pid=0, regardless of table-level grants (see
     *   DataHandler::hasPermissionToUpdate()'s VirtualRecord::RootPage
     *   branch).
     * - pid>0 (sysfolder-stored fragment): DataHandler's
     *   hasPageContextPermission() also requires Permission::CONTENT_EDIT
     *   on the fragment's own containing page for any non-"pages" table,
     *   independent of tables_modify. A user who can merely SEE that page
     *   is not necessarily allowed to content-edit on it.
     */
    private function canEditFragmentRow(bool $hasTableLevelModifyAccess, int $fragmentPid, BackendUserAuthentication $backendUser, array &$pageEditAccessCache): bool
    {
        if (!$hasTableLevelModifyAccess) {
            return false;
        }
        if ($fragmentPid <= 0) {
            return $backendUser->isAdmin();
        }
        if (!array_key_exists($fragmentPid, $pageEditAccessCache)) {
            $pageRow = BackendUtility::getRecord('pages', $fragmentPid);
            $pageEditAccessCache[$fragmentPid] = $pageRow !== null && $backendUser->doesUserHaveAccess($pageRow, Permission::CONTENT_EDIT);
        }
        return $pageEditAccessCache[$fragmentPid];
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
