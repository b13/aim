<?php

declare(strict_types=1);

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Attribute\AsAiProvider;
use B13\Aim\DependencyInjection\AiMiddlewareCompilerPass;
use B13\Aim\DependencyInjection\AiProviderCompilerPass;
use B13\Aim\DependencyInjection\SymfonyAiCompilerPass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
};
