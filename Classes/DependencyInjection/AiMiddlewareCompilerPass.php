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

use B13\Aim\Middleware\AiMiddlewarePipeline;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all tagged AI middleware services and injects them
 * into the AiMiddlewarePipeline, sorted by priority descending.
 */
final class AiMiddlewareCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $tagName,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AiMiddlewarePipeline::class)) {
            return;
        }

        $pipeline = $container->findDefinition(AiMiddlewarePipeline::class);
        $middlewareServices = [];

        foreach ($container->findTaggedServiceIds($this->tagName) as $serviceName => $tags) {
            $definition = $container->findDefinition($serviceName);
            if ($definition->isAbstract()) {
                continue;
            }
            $definition->setPublic(true);

            $priority = 0;
            foreach ($tags as $attributes) {
                $priority = (int)($attributes['priority'] ?? 0);
            }

            $middlewareServices[] = [
                'reference' => new Reference($serviceName),
                'priority' => $priority,
            ];
        }

        // Sort by priority descending (highest priority runs first / outermost)
        usort($middlewareServices, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $references = array_map(
            static fn(array $item): Reference => $item['reference'],
            $middlewareServices,
        );

        $pipeline->setArgument('$middlewares', $references);
    }
}
