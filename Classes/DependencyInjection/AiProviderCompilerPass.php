<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\DependencyInjection;

use B13\Aim\Capability\AiCapabilityInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Provider\ProviderFeatures;
use B13\Aim\Registry\AiProviderRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AiProviderCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $tagName,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AiProviderRegistry::class)) {
            return;
        }
        $registry = $container->findDefinition(AiProviderRegistry::class);

        foreach ($container->findTaggedServiceIds($this->tagName) as $serviceName => $tags) {
            $definition = $container->findDefinition($serviceName);
            if (!$definition->isAutoconfigured() || $definition->isAbstract()) {
                continue;
            }
            $definition->setPublic(true);

            $capabilities = $this->discoverCapabilities($definition->getClass() ?? $serviceName);

            foreach ($tags as $attributes) {
                $featuresDefinition = new Definition(ProviderFeatures::class);
                $featuresData = $attributes['features'] ?? [];
                $featuresDefinition->setFactory([ProviderFeatures::class, 'fromArray']);
                $featuresDefinition->setArguments([$featuresData]);

                $manifest = new Definition(AiProviderManifest::class);
                $manifest->setArguments([
                    $attributes['identifier'],
                    $attributes['name'],
                    $attributes['description'],
                    $attributes['iconIdentifier'],
                    $attributes['supportedModels'],
                    $capabilities,
                    $serviceName,
                    new Reference(ContainerInterface::class),
                    $featuresDefinition,
                    $attributes['modelCapabilities'] ?? [],
                ]);
                $manifest->setShared(false);

                $registry->addMethodCall('addProvider', [$manifest]);
            }
        }
    }

    /**
     * Discover which capability interfaces a provider class implements.
     *
     * @return array<class-string<AiCapabilityInterface>>
     */
    private function discoverCapabilities(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }
        $capabilities = [];
        foreach (class_implements($className) ?: [] as $interface) {
            if ($interface !== AiCapabilityInterface::class && is_subclass_of($interface, AiCapabilityInterface::class)) {
                $capabilities[] = $interface;
            }
        }
        return $capabilities;
    }
}
