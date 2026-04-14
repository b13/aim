<?php

declare(strict_types=1);

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Attribute\AsAiProvider;
use B13\Aim\DependencyInjection\AiMiddlewareCompilerPass;
use B13\Aim\DependencyInjection\AiProviderCompilerPass;
use B13\Aim\DependencyInjection\SymfonyAiCompilerPass;
use B13\Aim\Widgets\Provider\ExtensionUsageDataProvider;
use B13\Aim\Widgets\Provider\ModelUsageDataProvider;
use B13\Aim\Widgets\Provider\ProviderUsageDataProvider;
use B13\Aim\Widgets\Provider\RecentRequestsDataProvider;
use B13\Aim\Widgets\Provider\RequestLogButtonProvider;
use B13\Aim\Widgets\Provider\SuccessRateDataProvider;
use B13\Aim\Widgets\RecentRequestsWidget;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\CMS\Dashboard\Widgets\BarChartWidget;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder) {
    // AI Provider registration via #[AsAiProvider] attribute
    $containerBuilder->registerAttributeForAutoconfiguration(
        AsAiProvider::class,
        static function (ChildDefinition $definition, AsAiProvider $attribute): void {
            $definition->addTag(AsAiProvider::TAG_NAME, [
                'identifier' => $attribute->identifier,
                'name' => $attribute->name,
                'description' => $attribute->description,
                'iconIdentifier' => $attribute->iconIdentifier,
                'supportedModels' => $attribute->supportedModels,
                'features' => $attribute->features,
                'modelCapabilities' => $attribute->modelCapabilities,
            ]);
        }
    );
    $containerBuilder->addCompilerPass(new AiProviderCompilerPass(AsAiProvider::TAG_NAME));

    // AI Middleware registration via #[AsAiMiddleware] attribute
    $containerBuilder->registerAttributeForAutoconfiguration(
        AsAiMiddleware::class,
        static function (ChildDefinition $definition, AsAiMiddleware $attribute): void {
            $definition->addTag(AsAiMiddleware::TAG_NAME, [
                'priority' => $attribute->priority,
            ]);
        }
    );
    $containerBuilder->addCompilerPass(new AiMiddlewareCompilerPass(AsAiMiddleware::TAG_NAME));

    // Auto-discover Symfony AI Platform bridges (only activates if symfony/ai-platform is installed)
    $containerBuilder->addCompilerPass(new SymfonyAiCompilerPass());

    // Dashboard widgets (only registered when EXT:dashboard is installed)
    if ($containerBuilder->hasDefinition(WidgetRegistry::class)) {
        $services = $container->services();

        $services->set(RecentRequestsDataProvider::class)->public()->autowire();
        $services->set(ProviderUsageDataProvider::class)->public()->autowire();
        $services->set(ModelUsageDataProvider::class)->public()->autowire();
        $services->set(SuccessRateDataProvider::class)->public()->autowire();
        $services->set(ExtensionUsageDataProvider::class)->public()->autowire();

        $services->set('dashboard.buttons.aim_request_log')
            ->class(RequestLogButtonProvider::class)
            ->arg('$title', 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:widgets.button.requestLog');

        $services->set('dashboard.widget.aim_recent_requests')
            ->class(RecentRequestsWidget::class)
            ->arg('$dataProvider', new Reference(RecentRequestsDataProvider::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->arg('$buttonProvider', new Reference('dashboard.buttons.aim_request_log'))
            ->arg('$options', ['refreshAvailable' => true])
            ->tag('dashboard.widget', [
                'identifier' => 'aim_recent_requests',
                'groupNames' => 'aim',
                'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:widgets.recentRequests.title',
                'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:widgets.recentRequests.description',
                'iconIdentifier' => 'tx-aim',
                'height' => 'medium',
                'width' => 'medium',
            ]);

        $chartWidgets = [
            'aim_provider_usage' => ['class' => DoughnutChartWidget::class, 'provider' => ProviderUsageDataProvider::class, 'title' => 'widgets.providerUsage'],
            'aim_model_usage' => ['class' => BarChartWidget::class, 'provider' => ModelUsageDataProvider::class, 'title' => 'widgets.modelUsage'],
            'aim_success_rate' => ['class' => DoughnutChartWidget::class, 'provider' => SuccessRateDataProvider::class, 'title' => 'widgets.successRate'],
            'aim_extension_usage' => ['class' => DoughnutChartWidget::class, 'provider' => ExtensionUsageDataProvider::class, 'title' => 'widgets.extensionUsage'],
        ];

        foreach ($chartWidgets as $identifier => $config) {
            $services->set('dashboard.widget.' . $identifier)
                ->class($config['class'])
                ->arg('$dataProvider', new Reference($config['provider']))
                ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
                ->arg('$options', ['refreshAvailable' => true])
                ->tag('dashboard.widget', [
                    'identifier' => $identifier,
                    'groupNames' => 'aim',
                    'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:' . $config['title'] . '.title',
                    'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:' . $config['title'] . '.description',
                    'iconIdentifier' =>'tx-aim',
                    'height' => 'medium',
                    'width' => 'small',
                ]);
        }
    }
};
