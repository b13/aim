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

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\ProviderConfigurationDemand;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Pagination\DemandedArrayPaginator;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Registry\DisabledModelRegistry;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\TextGenerationRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\Action\ShortcutButton;
use B13\Aim\Backend\Button\RawHtmlButton;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
class ProviderController
{
    public function __construct(
        private readonly IconFactory $iconFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly ProviderConfigurationRepository $configurationRepository,
        private readonly AiProviderRegistry $providerRegistry,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly DisabledModelRegistry $disabledModelRegistry,
        private readonly RequestLogRepository $requestLogRepository,
        private readonly Registry $registry,
    ) {}

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        if (method_exists($view, 'makeDocHeaderModuleMenu')) {
            $view->makeDocHeaderModuleMenu();
        }
        $languageService = $this->getLanguageService();
        $view->setTitle($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:overview.title'));

        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();

        // "New configuration" button
        $newButton = GeneralUtility::makeInstance(LinkButton::class)
            ->setHref((string)$this->uriBuilder->buildUriFromRoute(
                'record_edit',
                [
                    'edit' => ['tx_aim_configuration' => ['new']],
                    'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('aim_providers'),
                ]
            ))
            ->setShowLabelText(true)
            ->setTitle($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:overview.newConfiguration'))
            ->setIcon($this->iconFactory->getIcon('actions-plus', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL));
        $buttonBar->addButton($newButton, ButtonBar::BUTTON_POSITION_LEFT, 10);

        // "Available providers" button (opens AJAX modal)
        $hasProviders = $this->providerRegistry->getProviders() !== [];
        if ($hasProviders) {
            $label = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:overview.showAvailableProviders');
            $icon = $this->iconFactory->getIcon('actions-rocket', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL);
            $url = (string)$this->uriBuilder->buildUriFromRoute('ajax_aim_available_providers');
            $modalTitle = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:overview.availableProviders');
            $availableProvidersButton = GeneralUtility::makeInstance(RawHtmlButton::class)
                ->setHtml(
                    '<aim-available-providers url="' . htmlspecialchars($url) . '" modal-title="' . htmlspecialchars($modalTitle) . '">'
                    . '<button type="button" class="btn btn-sm" title="' . htmlspecialchars($label) . '">'
                    . $icon->render() . htmlspecialchars($label)
                    . '</button>'
                    . '</aim-available-providers>'
                );
            $buttonBar->addButton($availableProvidersButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
        }

        // Reload (v14 auto-adds it)
        if (!class_exists(ComponentFactory::class)) {
            $reloadButton = GeneralUtility::makeInstance(LinkButton::class)
                ->setHref($request->getAttribute('normalizedParams')->getRequestUri())
                ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
                ->setIcon($this->iconFactory->getIcon('actions-refresh', class_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL));
            $buttonBar->addButton($reloadButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
        }

        // Shortcut
        $shortcutButton = GeneralUtility::makeInstance(ShortcutButton::class)
            ->setRouteIdentifier('aim_providers')
            ->setDisplayName($languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:overview.title'));
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT, 3);

        $demand = ProviderConfigurationDemand::fromRequest($request);
        $configurations = $this->configurationRepository->findByDemand($demand);
        $totalCount = $this->configurationRepository->countByDemand($demand);

        $rows = array_map(function (ProviderConfiguration $configuration): array {
            $row = $configuration->row;
            $row['modelDisabled'] = $this->disabledModelRegistry->isDisabled(
                $configuration->providerIdentifier,
                $configuration->model,
            );
            return $row;
        }, $configurations);

        $paginator = new DemandedArrayPaginator(
            $rows,
            $demand->getPage(),
            $demand->getLimit(),
            $totalCount
        );
        $pagination = new SimplePagination($paginator);

        $providerTypes = [];
        foreach ($this->providerRegistry->getProviders() as $manifest) {
            $providerTypes[$manifest->identifier] = $languageService->sL($manifest->name) ?: $manifest->name;
        }

        $paginationBaseUrl = (string)$this->uriBuilder->buildUriFromRoute('aim_providers', [
            'orderField' => $demand->getOrderField(),
            'orderDirection' => $demand->getOrderDirection(),
        ]);

        return $view->assignMultiple([
            'demand' => $demand,
            'paginator' => $paginator,
            'pagination' => $pagination,
            'paginationBaseUrl' => $paginationBaseUrl,
            'hasProviders' => $hasProviders,
            'providerTypes' => $providerTypes,
            'verificationResults' => $this->getVerificationResults(),
            'lastUsed' => $this->requestLogRepository->getLastUsedPerConfiguration(),
        ])->renderResponse('Aim/Overview');
    }

    public function availableProvidersAction(ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $providers = array_map(
            fn(AiProviderManifest $manifest): array => [
                'identifier' => $manifest->identifier,
                'name' => $languageService->sL($manifest->name) ?: $manifest->name,
                'iconIdentifier' => $manifest->iconIdentifier,
                'models' => array_map(
                    fn(string $modelId) => [
                        'id' => $modelId,
                        'disabled' => $this->disabledModelRegistry->isDisabled($manifest->identifier, $modelId),
                    ],
                    array_keys($manifest->supportedModels),
                ),
                'capabilities' => $this->resolveCapabilityLabels($manifest->capabilities),
            ],
            $this->providerRegistry->getProviders(),
        );

        $view = $this->moduleTemplateFactory->create($request);
        $view->assignMultiple([
            'providers' => $providers,
            'disabledModels' => $this->disabledModelRegistry->getAll(),
            'toggleUrl' => (string)$this->uriBuilder->buildUriFromRoute('ajax_aim_toggle_model'),
        ]);
        return $view->renderResponse('Aim/AvailableProviders');
    }

    public function toggleModelAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $provider = trim((string)($body['provider'] ?? ''));
        $model = trim((string)($body['model'] ?? ''));

        if ($provider === '' || $model === '') {
            return new JsonResponse(['error' => 'Missing provider or model'], 400);
        }

        $nowDisabled = $this->disabledModelRegistry->toggle($provider, $model);

        return new JsonResponse([
            'provider' => $provider,
            'model' => $model,
            'disabled' => $nowDisabled,
        ]);
    }

    /**
     * Map capability FQCNs to human-readable labels via locallang.
     *
     * @param list<class-string> $capabilities
     * @return list<string>
     */
    private function resolveCapabilityLabels(array $capabilities): array
    {
        $map = [
            'VisionCapableInterface' => 'capability.vision',
            'ConversationCapableInterface' => 'capability.conversation',
            'TextGenerationCapableInterface' => 'capability.textGeneration',
            'TranslationCapableInterface' => 'capability.translation',
            'ToolCallingCapableInterface' => 'capability.toolCalling',
        ];

        $languageService = $this->getLanguageService();
        $labels = [];
        foreach ($capabilities as $fqcn) {
            $shortName = substr(strrchr($fqcn, '\\') ?: $fqcn, 1);
            if (isset($map[$shortName])) {
                $labels[] = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:' . $map[$shortName]);
            } else {
                $label = str_replace(['CapableInterface', 'Interface'], '', $shortName);
                $labels[] = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);
            }
        }
        return $labels;
    }

    /**
     * Verify that a provider configuration can reach its AI backend.
     *
     * Sends a minimal 1-token request to validate the API key, endpoint,
     * and model. Bypasses the middleware pipeline — this is a health probe,
     * not a real AI request.
     */
    public function verifyProviderAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $uid = (int)($body['uid'] ?? 0);

        if ($uid <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Missing configuration uid'], 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Configuration not found'], 404);
        }

        if (!$this->providerRegistry->hasProvider($configuration->providerIdentifier)) {
            return new JsonResponse(['ok' => false, 'message' => 'Provider "' . $configuration->providerIdentifier . '" is not registered'], 404);
        }

        $manifest = $this->providerRegistry->getProvider($configuration->providerIdentifier);
        $provider = $manifest->getInstance();

        // Build a minimal probe request to verify connectivity.
        // Prefer conversation over text generation since reasoning models
        // (o-series) work more reliably with the conversation API.
        // Use 256 max tokens to accommodate reasoning overhead.
        $start = hrtime(true);
        try {
            if ($provider instanceof ConversationCapableInterface) {
                $probeRequest = new ConversationRequest(
                    configuration: $configuration,
                    messages: [new UserMessage('Respond with the single word: hello')],
                    maxTokens: 256,
                );
                $response = $provider->processConversationRequest($probeRequest);
            } elseif ($provider instanceof TextGenerationCapableInterface) {
                $probeRequest = new TextGenerationRequest(
                    configuration: $configuration,
                    prompt: 'Respond with the single word: hello',
                    maxTokens: 256,
                );
                $response = $provider->processTextGenerationRequest($probeRequest);
            } else {
                return new JsonResponse(['ok' => false, 'message' => 'Provider does not support text generation or conversation for verification']);
            }
        } catch (\Throwable $e) {
            $result = [
                'ok' => false,
                'message' => $e->getMessage(),
                'checkedAt' => time(),
            ];
            $this->saveVerificationResult($uid, $result);
            return new JsonResponse($result);
        }
        $latencyMs = (int)((hrtime(true) - $start) / 1_000_000);

        if ($response->isSuccessful()) {
            $result = [
                'ok' => true,
                'message' => sprintf('Reachable — %s responded in %dms', $response->usage->modelUsed ?: $configuration->model, $latencyMs),
                'model' => $response->usage->modelUsed ?: $configuration->model,
                'latencyMs' => $latencyMs,
                'checkedAt' => time(),
            ];
        } else {
            $result = [
                'ok' => false,
                'message' => $response->errors[0] ?? 'Unknown error',
                'checkedAt' => time(),
            ];
        }

        // Persist for display on next page load
        $this->saveVerificationResult($uid, $result);

        return new JsonResponse($result);
    }

    private function saveVerificationResult(int $configUid, array $result): void
    {
        $all = $this->registry->get('aim', 'providerVerification', []);
        $all[$configUid] = $result;
        $this->registry->set('aim', 'providerVerification', $all);
    }

    private function getVerificationResults(): array
    {
        return $this->registry->get('aim', 'providerVerification', []);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
