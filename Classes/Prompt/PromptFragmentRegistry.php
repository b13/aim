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

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Loads and merges code-registered prompt fragments from all extensions.
 *
 * Each extension can provide:
 *   Configuration/SystemPrompt/PromptFragments.php
 *
 * The file returns a list of fragments:
 *   return [
 *       ['prompt' => 'Always add a small diagonal watermark reading "DRAFT".', 'scope' => 'imageGeneration'],
 *       ['prompt' => 'Never use exclamation marks.'], // scope defaults to 'all'
 *       ['prompt' => 'Mention accessibility.', 'scope' => ['text', 'vision']], // or a comma string 'text,vision'
 *   ];
 *
 * Unlike page-tree fragments (PagePromptResolver), these apply regardless
 * of page context: they represent extension-level policy (e.g. a brand or
 * compliance rule), not page-specific tone. Mirrors the merge approach of
 * ComplexitySignalRegistry (Classes/Routing/ComplexitySignalRegistry.php).
 */
#[Autoconfigure(public: true)]
class PromptFragmentRegistry
{
    /** @var list<array{prompt: string, scope: list<string>}>|null */
    private ?array $fragments = null;

    /**
     * Guards against re-logging the missing-cache-pool warning on every
     * single request while it stays broken.
     */
    private static bool $missingCacheWarningLogged = false;

    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly CacheManager $cacheManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<string> prompt texts matching the given scope, in registration order
     */
    public function getFragments(PromptFragmentScope $scope): array
    {
        return $scope->filterPrompts($this->loadAll());
    }

    /**
     * @return list<array{prompt: string, scope: list<string>}>
     */
    private function loadAll(): array
    {
        if ($this->fragments !== null) {
            return $this->fragments;
        }

        // Package fragments are cached across requests (loadFromPackages()):
        // scanning every active package's filesystem on every single AI call
        // is wasted I/O for content that only changes on deploy/(de)activation.
        // The $GLOBALS runtime override is deliberately NOT part of that cache:
        // it exists precisely so other code can set it dynamically per request/
        // context, and freezing it into a persistent cache would defeat that.
        $merged = $this->loadFromPackages();

        $globals = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments'] ?? [];
        if (is_array($globals)) {
            $this->appendFragments($merged, $globals);
        }

        return $this->fragments = $merged;
    }

    /**
     * @return list<array{prompt: string, scope: list<string>}>
     */
    private function loadFromPackages(): array
    {
        // The cache pool is a pure performance optimization (avoids scanning
        // every active package's filesystem on every AI call); if it isn't
        // registered for whatever reason (a misbehaving custom cache backend,
        // an environment where ext_localconf.php's registration didn't take
        // effect), that must degrade to an uncached scan, not take down
        // prompt composition entirely.
        try {
            $cache = $this->cacheManager->getCache('aim_prompt_fragments');
        } catch (\TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException $e) {
            if (!self::$missingCacheWarningLogged) {
                self::$missingCacheWarningLogged = true;
                $this->logger->warning('The "aim_prompt_fragments" cache pool is not registered, falling back to an uncached package scan on every call: ' . $e->getMessage());
            }
            return $this->scanPackages();
        }

        $cached = $cache->get('packages');
        if (is_array($cached)) {
            return $cached;
        }

        $merged = $this->scanPackages();
        $cache->set('packages', $merged);
        return $merged;
    }

    /**
     * @return list<array{prompt: string, scope: list<string>}>
     */
    private function scanPackages(): array
    {
        $merged = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $file = $package->getPackagePath() . 'Configuration/SystemPrompt/PromptFragments.php';
            if (!is_file($file)) {
                continue;
            }
            try {
                $fragments = require $file;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'Could not load prompt fragments from "%s" (package "%s"), skipping: %s',
                    $file,
                    $package->getPackageKey(),
                    $e->getMessage(),
                ));
                continue;
            }
            if (is_array($fragments)) {
                $this->appendFragments($merged, $fragments);
            }
        }
        return $merged;
    }

    /**
     * @param list<array{prompt: string, scope: list<string>}> $merged
     */
    private function appendFragments(array &$merged, array $fragments): void
    {
        foreach ($fragments as $fragment) {
            if (!is_array($fragment) || !isset($fragment['prompt']) || !is_string($fragment['prompt'])) {
                continue;
            }
            $prompt = trim($fragment['prompt']);
            if ($prompt === '') {
                continue;
            }
            $rawScope = $fragment['scope'] ?? '';
            $scope = (is_string($rawScope) || is_array($rawScope)) ? $rawScope : '';
            $merged[] = [
                'prompt' => $prompt,
                'scope' => PromptFragmentScope::parseList($scope, $this->logger),
            ];
        }
    }
}
