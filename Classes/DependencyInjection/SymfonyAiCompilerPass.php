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

use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\ToolCallingCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Provider\ProviderFeatures;
use B13\Aim\Provider\SymfonyAi\SymfonyAiPlatformAdapter;
use B13\Aim\Registry\AiProviderRegistry;
use Composer\InstalledVersions;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Auto-discovers installed Symfony AI bridge packages and registers them
 * as AiM providers.
 *
 * Instead of maintaining a static list of known bridges, this compiler pass
 * scans all installed Composer packages matching the `symfony/ai-*-platform`
 * naming convention. For each bridge it:
 *
 * 1. Derives the PHP namespace from the package's autoload configuration
 * 2. Checks for Factory and ModelCatalog classes
 * 3. Reads models and per-model capabilities from the ModelCatalog
 * 4. Sanitizes model names for TCA compatibility (no colons)
 * 5. Detects the factory authentication parameter via reflection
 * 6. Registers a SymfonyAiPlatformAdapter as a AiM provider
 */
final class SymfonyAiCompilerPass implements CompilerPassInterface
{
    /**
     * Symfony AI Capability enum → AiM capability interface mapping.
     *
     * Only capabilities relevant to AiM's interfaces are mapped.
     * Unmapped Symfony AI capabilities (audio, video, image generation, etc.)
     * are silently ignored — AiM doesn't support them yet.
     */
    private const CAPABILITY_MAP = [
        'input-image' => VisionCapableInterface::class,
        'input-messages' => ConversationCapableInterface::class,
        'output-text' => TextGenerationCapableInterface::class,
        'tool-calling' => ToolCallingCapableInterface::class,
        'embeddings' => EmbeddingCapableInterface::class,
    ];

    /**
     * All AiM capability interfaces — used as the provider-level default
     * when a bridge has no ModelCatalog to read from.
     */
    private const ALL_CAPABILITIES = [
        VisionCapableInterface::class,
        ConversationCapableInterface::class,
        TextGenerationCapableInterface::class,
        TranslationCapableInterface::class,
        ToolCallingCapableInterface::class,
        EmbeddingCapableInterface::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists('Symfony\AI\Platform\PlatformInterface')) {
            return;
        }
        if (!class_exists(InstalledVersions::class)) {
            return;
        }
        if (!$container->hasDefinition(AiProviderRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(AiProviderRegistry::class);

        foreach ($this->discoverBridgePackages() as $package) {
            $bridge = $this->buildBridgeDefinition($package);
            if ($bridge === null) {
                continue;
            }

            $serviceId = 'aim.symfony_ai.' . $bridge['identifier'];

            $adapterDefinition = new Definition(SymfonyAiPlatformAdapter::class);
            $adapterDefinition->setArguments([
                $bridge['factoryClass'],
                $bridge['factoryParam'],
            ]);
            $adapterDefinition->setPublic(true);
            $container->setDefinition($serviceId, $adapterDefinition);

            $featuresDefinition = new Definition(ProviderFeatures::class);
            $featuresDefinition->setFactory([ProviderFeatures::class, 'fromArray']);
            $featuresDefinition->setArguments([$bridge['features']]);

            $manifest = new Definition(AiProviderManifest::class);
            $manifest->setArguments([
                $bridge['identifier'],
                $bridge['name'],
                $bridge['description'],
                'tx-aim',
                $bridge['models'],
                self::ALL_CAPABILITIES,
                $serviceId,
                new Reference(ContainerInterface::class),
                $featuresDefinition,
                $bridge['modelCapabilities'],
            ]);
            $manifest->setShared(false);

            $registry->addMethodCall('addProvider', [$manifest]);
        }
    }

    /**
     * Discover installed Symfony AI bridge packages via Composer's runtime API.
     *
     * @return list<array{name: string, namespace: string}>
     */
    private function discoverBridgePackages(): array
    {
        $bridges = [];

        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            // Match: symfony/ai-*-platform (e.g. symfony/ai-open-ai-platform, symfony/ai-ollama-platform)
            if (!str_starts_with($packageName, 'symfony/ai-') || !str_ends_with($packageName, '-platform')) {
                continue;
            }
            // Exclude the core platform package itself
            if ($packageName === 'symfony/ai-platform') {
                continue;
            }

            $namespace = $this->resolveNamespace($packageName);
            if ($namespace === null) {
                continue;
            }

            $bridges[] = [
                'name' => $packageName,
                'namespace' => $namespace,
            ];
        }

        return $bridges;
    }

    /**
     * Resolve the PSR-4 namespace for a package by reading its composer.json.
     */
    private function resolveNamespace(string $packageName): ?string
    {
        try {
            $installPath = InstalledVersions::getInstallPath($packageName);
        } catch (\OutOfBoundsException) {
            return null;
        }

        if ($installPath === null || !is_file($installPath . '/composer.json')) {
            return null;
        }

        try {
            $composerJson = json_decode(file_get_contents($installPath . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $autoload = $composerJson['autoload']['psr-4'] ?? [];

        // Return the first (and typically only) PSR-4 namespace
        $namespace = array_key_first($autoload);
        return $namespace !== null ? rtrim($namespace, '\\') : null;
    }

    /**
     * Build a complete bridge definition from a discovered package.
     *
     * @return array{identifier: string, name: string, description: string, factoryClass: string, factoryParam: string, models: array<string, string>, modelCapabilities: array<string, list<string>>, features: array<string, mixed>}|null
     */
    private function buildBridgeDefinition(array $package): ?array
    {
        $namespace = $package['namespace'];
        $factoryClass = $namespace . '\\Factory';

        if (!class_exists($factoryClass)) {
            return null;
        }

        // Derive identifier from package name: symfony/ai-open-ai-platform → openai
        $identifier = $this->deriveIdentifier($package['name']);

        // Derive display name from package name
        $name = 'Symfony AI: ' . $this->deriveName($package['name']);

        // Detect factory auth parameter via reflection
        $factoryParam = $this->detectFactoryParam($factoryClass);

        // Read models + capabilities from ModelCatalog
        $catalogClass = $namespace . '\\ModelCatalog';
        $models = [];
        $modelCapabilities = [];
        $features = ['supportsStreaming' => true];

        if (class_exists($catalogClass)) {
            try {
                $catalog = new $catalogClass();
                if (method_exists($catalog, 'getModels')) {
                    [$models, $modelCapabilities, $features] = $this->extractModelsFromCatalog(
                        $catalog->getModels(),
                        $features,
                    );
                }
            } catch (\Throwable) {
                // Catalog instantiation failed — continue with empty models
            }
        }

        // Skip bridges with no discoverable models (e.g. shared/internal packages)
        if ($models === []) {
            return null;
        }

        return [
            'identifier' => $identifier,
            'name' => $name,
            'description' => $this->deriveName($package['name']) . ' models via Symfony AI',
            'factoryClass' => $factoryClass,
            'factoryParam' => $factoryParam,
            'models' => $models,
            'modelCapabilities' => $modelCapabilities,
            'features' => $features,
        ];
    }

    /**
     * Extract models, per-model capabilities, and features from a ModelCatalog.
     *
     * @param array<string, array{class: class-string, capabilities: list<object>}> $catalogModels
     * @return array{0: array<string, string>, 1: array<string, list<string>>, 2: array<string, mixed>}
     */
    private function extractModelsFromCatalog(array $catalogModels, array $features): array
    {
        $models = [];
        $modelCapabilities = [];
        $hasStructuredOutput = false;
        $hasToolCalling = false;

        foreach ($catalogModels as $modelId => $modelConfig) {
            // Sanitize model ID: colons break TYPO3's LanguageService::sL()
            $safeModelId = $this->sanitizeModelId($modelId);
            if ($safeModelId === '') {
                continue;
            }

            // Build display label from model ID
            $models[$safeModelId] = $this->buildModelLabel($safeModelId, $modelConfig['capabilities'] ?? []);

            // Map Symfony AI capabilities to AiM interfaces
            $aiCapabilities = $this->mapCapabilities($modelConfig['capabilities'] ?? []);
            if ($aiCapabilities !== []) {
                $modelCapabilities[$safeModelId] = $aiCapabilities;
            }

            // Detect features from capabilities
            foreach ($modelConfig['capabilities'] ?? [] as $cap) {
                $capValue = is_object($cap) && property_exists($cap, 'value') ? $cap->value : (string)$cap;
                if ($capValue === 'output-structured') {
                    $hasStructuredOutput = true;
                }
                if ($capValue === 'tool-calling') {
                    $hasToolCalling = true;
                }
            }
        }

        if ($hasStructuredOutput) {
            $features['supportsStructuredOutput'] = true;
        }
        if ($hasToolCalling) {
            $features['supportsParallelToolCalls'] = true;
        }

        return [$models, $modelCapabilities, $features];
    }

    /**
     * Map Symfony AI Capability enum values to AiM capability interfaces.
     *
     * @param list<object> $capabilities Symfony AI Capability enum instances
     * @return list<class-string>
     */
    private function mapCapabilities(array $capabilities): array
    {
        $mapped = [];
        foreach ($capabilities as $cap) {
            $capValue = is_object($cap) && property_exists($cap, 'value') ? $cap->value : (string)$cap;
            if (isset(self::CAPABILITY_MAP[$capValue])) {
                $mapped[self::CAPABILITY_MAP[$capValue]] = true;
            }
        }

        // If model has INPUT_MESSAGES + OUTPUT_TEXT, it also supports translation
        // (translation is text generation with a specific prompt — not a separate Symfony AI capability)
        if (isset($mapped[ConversationCapableInterface::class], $mapped[TextGenerationCapableInterface::class])) {
            $mapped[TranslationCapableInterface::class] = true;
        }

        return array_keys($mapped);
    }

    /**
     * Sanitize a model ID for TCA compatibility.
     *
     * TYPO3's LanguageService::sL() interprets colons as domain reference
     * separators, causing "Package not found" errors for model IDs like
     * "llama3.2:1b". We strip the colon and everything after it.
     */
    private function sanitizeModelId(string $modelId): string
    {
        if (str_contains($modelId, ':')) {
            $modelId = explode(':', $modelId, 2)[0];
        }
        return trim($modelId);
    }

    /**
     * Build a human-readable label for a model.
     *
     * @param list<object> $capabilities
     */
    private function buildModelLabel(string $modelId, array $capabilities): string
    {
        $capValues = array_map(
            static fn($cap) => is_object($cap) && property_exists($cap, 'value') ? $cap->value : (string)$cap,
            $capabilities,
        );

        // Tag embedding-only models
        if (in_array('embeddings', $capValues, true) && !in_array('output-text', $capValues, true)) {
            return 'Embedding: ' . $modelId;
        }

        return $modelId;
    }

    /**
     * Derive a AiM provider identifier from a Composer package name.
     *
     * symfony/ai-open-ai-platform → openai
     * symfony/ai-ollama-platform → ollama
     * symfony/ai-anthropic-platform → anthropic
     */
    private function deriveIdentifier(string $packageName): string
    {
        // Remove symfony/ai- prefix and -platform suffix
        $slug = preg_replace('/^symfony\/ai-/', '', $packageName);
        $slug = preg_replace('/-platform$/', '', $slug);

        // Remove hyphens for the identifier: open-ai → openai
        return str_replace('-', '', $slug);
    }

    /**
     * Derive a display name from a Composer package name.
     *
     * symfony/ai-open-ai-platform → OpenAI
     * symfony/ai-ollama-platform → Ollama
     * symfony/ai-anthropic-platform → Anthropic
     */
    private function deriveName(string $packageName): string
    {
        $slug = preg_replace('/^symfony\/ai-/', '', $packageName);
        $slug = preg_replace('/-platform$/', '', $slug);

        // Known name mappings for better display
        $nameMap = [
            'open-ai' => 'OpenAI',
            'open-responses' => 'OpenAI Responses',
        ];

        return $nameMap[$slug] ?? implode(' ', array_map('ucfirst', explode('-', $slug)));
    }

    /**
     * Detect the primary authentication parameter of a Factory::createProvider() method.
     *
     * Most bridges use `apiKey`. Some (Ollama, LM Studio) use `endpoint`.
     * We check the first parameter name via reflection.
     */
    private function detectFactoryParam(string $factoryClass): string
    {
        try {
            $method = new \ReflectionMethod($factoryClass, 'createProvider');
            foreach ($method->getParameters() as $param) {
                $name = $param->getName();
                if ($name === 'endpoint' || $name === 'hostUrl' || $name === 'baseUrl') {
                    return 'endpoint';
                }
                if ($name === 'apiKey') {
                    return 'apiKey';
                }
            }
        } catch (\ReflectionException) {
        }

        return 'apiKey';
    }
}
