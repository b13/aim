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

use B13\Aim\Domain\Repository\RequestLogDemand;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Pagination\DemandedArrayPaginator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\Action\ShortcutButton;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
class RequestLogController
{
    public function __construct(
        private readonly IconFactory $iconFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly RequestLogRepository $logRepository,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
    ) {}

    public function logAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        if (method_exists($view, 'makeDocHeaderModuleMenu')) {
            $view->makeDocHeaderModuleMenu();
        }
        $languageService = $this->getLanguageService();
        $view->setTitle($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:requestLog.title'));

        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();

        // Reload
        if (!class_exists(ComponentFactory::class)) {
            $reloadButton = GeneralUtility::makeInstance(LinkButton::class)
                ->setHref($request->getAttribute('normalizedParams')->getRequestUri())
                ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
                ->setIcon($this->iconFactory->getIcon('actions-refresh', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL));
            $buttonBar->addButton($reloadButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
        }

        // Shortcut
        $shortcutButton = GeneralUtility::makeInstance(ShortcutButton::class)
            ->setRouteIdentifier('aim_request_log')
            ->setDisplayName($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:requestLog.title'));
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT, 3);

        $demand = RequestLogDemand::fromRequest($request);
        $logEntries = $this->logRepository->findByDemand($demand);
        $totalCount = $this->logRepository->countByDemand($demand);

        $paginator = new DemandedArrayPaginator(
            $logEntries,
            $demand->getPage(),
            $demand->getLimit(),
            $totalCount,
        );
        $pagination = new SimplePagination($paginator);

        $statistics = $this->logRepository->getStatistics();

        // Build pagination base URL with demand filters (append &page=N in template)
        $paginationBaseParams = [
            'orderField' => $demand->getOrderField(),
            'orderDirection' => $demand->getOrderDirection(),
        ];
        foreach ($demand->getParameters() as $key => $value) {
            $paginationBaseParams['demand[' . $key . ']'] = $value;
        }
        $paginationBaseUrl = (string)$this->uriBuilder->buildUriFromRoute('aim_request_log', $paginationBaseParams);

        // Resolve user_id -> username for display
        $userIds = array_unique(array_filter(array_map(
            static fn(array $entry) => (int)($entry['user_id'] ?? 0),
            $paginator->getPaginatedItems(),
        )));
        $userMap = $this->logRepository->resolveUsernames($userIds);

        return $view->assignMultiple([
            'demand' => $demand,
            'paginationBaseUrl' => $paginationBaseUrl,
            'paginator' => $paginator,
            'pagination' => $pagination,
            'statistics' => $statistics,
            'userMap' => $userMap,
            'providers' => $this->logRepository->getDistinctProviders(),
            'extensionKeys' => $this->logRepository->getDistinctExtensionKeys(),
            'requestTypes' => $this->logRepository->getDistinctRequestTypes(),
        ])->renderResponse('Aim/RequestLog');
    }

    public function pollAction(ServerRequestInterface $request): ResponseInterface
    {
        $demand = RequestLogDemand::fromRequest($request);
        $logEntries = $this->logRepository->findByDemand($demand);
        $totalCount = $this->logRepository->countByDemand($demand);
        $statistics = $this->logRepository->getStatistics();

        $rows = [];
        foreach ($logEntries as $entry) {
            $rows[] = [
                'crdate' => date('Y-m-d H:i:s', (int)$entry['crdate']),
                'extension_key' => $entry['extension_key'] ?? '',
                'request_type' => $entry['request_type'] ?? '',
                'provider_identifier' => $entry['provider_identifier'] ?? '',
                'model_used' => $entry['model_used'] ?: ($entry['model_requested'] ?? ''),
                'model_requested' => $entry['model_requested'] ?? '',
                'total_tokens' => (int)($entry['total_tokens'] ?? 0),
                'prompt_tokens' => (int)($entry['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($entry['completion_tokens'] ?? 0),
                'cached_tokens' => (int)($entry['cached_tokens'] ?? 0),
                'reasoning_tokens' => (int)($entry['reasoning_tokens'] ?? 0),
                'cost' => number_format((float)($entry['cost'] ?? 0), 6),
                'duration_ms' => number_format((int)($entry['duration_ms'] ?? 0), 0, '.', ','),
                'success' => (bool)($entry['success'] ?? false),
                'rerouted' => (bool)($entry['rerouted'] ?? false),
                'error_message' => $entry['error_message'] ?? '',
                'request_prompt' => mb_substr((string)($entry['request_prompt'] ?? ''), 0, 200),
                'response_content' => mb_substr((string)($entry['response_content'] ?? ''), 0, 200),
                'complexity_label' => $entry['complexity_label'] ?? '',
                'complexity_score' => (float)($entry['complexity_score'] ?? 0),
                'reroute_type' => $entry['reroute_type'] ?? '',
                'reroute_reason' => $entry['reroute_reason'] ?? '',
            ];
        }

        return new JsonResponse([
            'statistics' => $statistics,
            'rows' => $rows,
            'totalCount' => $totalCount,
        ]);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
