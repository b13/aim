<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Prompt;

use B13\Aim\Prompt\PromptFragmentRegistry;
use B13\Aim\Prompt\PromptFragmentScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;

final class PromptFragmentRegistryTest extends TestCase
{
    private function packageAt(string $relativeFixturePath): PackageInterface
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getPackagePath')->willReturn(__DIR__ . '/Fixtures/' . $relativeFixturePath . '/');
        return $package;
    }

    /**
     * A cache frontend that always misses and discards writes — the default
     * for tests that don't care about caching behavior itself.
     */
    private function alwaysMissCache(): FrontendInterface
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        return $cache;
    }

    private function createRegistry(array $packages, ?FrontendInterface $cache = null): PromptFragmentRegistry
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn($packages);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('aim_prompt_fragments')->willReturn($cache ?? $this->alwaysMissCache());
        return new PromptFragmentRegistry($packageManager, $cacheManager, new NullLogger());
    }

    #[Test]
    public function mergesFragmentsFromEveryPackageDefaultingToAllScope(): void
    {
        $registry = $this->createRegistry([$this->packageAt('PackageOne'), $this->packageAt('PackageTwo')]);

        self::assertSame(
            ['Never use exclamation marks.'],
            $registry->getFragments(PromptFragmentScope::Text),
        );
    }

    #[Test]
    public function filtersByScopeWhileStillIncludingAllScopeFragments(): void
    {
        $registry = $this->createRegistry([$this->packageAt('PackageOne'), $this->packageAt('PackageTwo')]);

        self::assertSame(
            ['Never use exclamation marks.', 'Always add a small diagonal watermark reading "DRAFT".'],
            $registry->getFragments(PromptFragmentScope::ImageGeneration),
        );
        self::assertSame(
            ['Never use exclamation marks.', 'Always mention accessibility considerations.'],
            $registry->getFragments(PromptFragmentScope::Vision),
        );
    }

    #[Test]
    public function dropsBlankAndNonStringPromptEntries(): void
    {
        $registry = $this->createRegistry([$this->packageAt('PackageTwo')]);

        // The blank (post-trim) and non-string entries in PackageTwo's fixture
        // must not surface as empty/invalid fragments for any scope.
        self::assertSame(
            ['Always mention accessibility considerations.'],
            $registry->getFragments(PromptFragmentScope::Vision),
        );
        self::assertSame([], $registry->getFragments(PromptFragmentScope::Text));
    }

    #[Test]
    public function ignoresPackagesWithoutAPromptFragmentsFile(): void
    {
        $withoutFile = $this->createMock(PackageInterface::class);
        $withoutFile->method('getPackagePath')->willReturn(__DIR__ . '/Fixtures/DoesNotExist/');

        $registry = $this->createRegistry([$withoutFile]);

        self::assertSame([], $registry->getFragments(PromptFragmentScope::All));
    }

    #[Test]
    public function normalizesUnknownScopeToAllInsteadOfSilentlyDroppingIt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments'] = [
            ['prompt' => 'Typo in scope.', 'scope' => 'imageGeneraton'],
        ];
        try {
            $registry = $this->createRegistry([]);

            // Normalized to "all" — must show up for an unrelated scope too.
            self::assertSame(['Typo in scope.'], $registry->getFragments(PromptFragmentScope::Conversation));
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments']);
        }
    }

    #[Test]
    public function reusesCachedPackageScanAcrossSeparateRegistryInstances(): void
    {
        // Simulates two requests sharing the same persistent cache pool: the
        // second registry's PackageManager must never be asked for active
        // packages once the first has populated the cache.
        $storage = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$storage) {
            return $storage[$key] ?? false;
        });
        $cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$storage): void {
            $storage[$key] = $value;
        });

        $firstRegistry = $this->createRegistry([$this->packageAt('PackageOne')], $cache);
        self::assertSame(['Never use exclamation marks.'], $firstRegistry->getFragments(PromptFragmentScope::Text));

        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->expects(self::never())->method('getActivePackages');
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('aim_prompt_fragments')->willReturn($cache);
        $secondRegistry = new PromptFragmentRegistry($packageManager, $cacheManager, new NullLogger());

        self::assertSame(['Never use exclamation marks.'], $secondRegistry->getFragments(PromptFragmentScope::Text));
    }

    #[Test]
    public function runtimeGlobalOverrideIsNeverCachedAndReflectsChangesImmediately(): void
    {
        // The $GLOBALS override must stay live even when the package-scan
        // portion is served from cache — otherwise a value set for one
        // request/context would leak into unrelated ones via the cache.
        $storage = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$storage) {
            return $storage[$key] ?? false;
        });
        $cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$storage): void {
            $storage[$key] = $value;
        });

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments'] = [
            ['prompt' => 'First value.'],
        ];
        try {
            $first = $this->createRegistry([], $cache);
            self::assertSame(['First value.'], $first->getFragments(PromptFragmentScope::All));

            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments'] = [
                ['prompt' => 'Second value.'],
            ];
            $second = $this->createRegistry([], $cache);
            self::assertSame(['Second value.'], $second->getFragments(PromptFragmentScope::All));
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments']);
        }
    }

    #[Test]
    public function fallsBackToAnUncachedScanWhenTheCachePoolIsNotRegistered(): void
    {
        // The cache pool is a pure performance optimization — if it isn't
        // registered (e.g. an environment where ext_localconf.php's
        // registration didn't take effect), fragment resolution must still
        // work, just without the persistence.
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([$this->packageAt('PackageOne')]);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('aim_prompt_fragments')->willThrowException(new NoSuchCacheException('aim_prompt_fragments', 1234567890));

        $registry = new PromptFragmentRegistry($packageManager, $cacheManager, new NullLogger());

        self::assertSame(['Never use exclamation marks.'], $registry->getFragments(PromptFragmentScope::Text));
    }

    #[Test]
    public function matchesAFragmentTaggedWithMultipleCapabilities(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments'] = [
            ['prompt' => 'Mention accessibility.', 'scope' => 'text,vision'],
        ];
        try {
            $registry = $this->createRegistry([]);

            self::assertSame(['Mention accessibility.'], $registry->getFragments(PromptFragmentScope::Text));
            self::assertSame(['Mention accessibility.'], $registry->getFragments(PromptFragmentScope::Vision));
            self::assertSame([], $registry->getFragments(PromptFragmentScope::Translation));
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments']);
        }
    }

    #[Test]
    public function mergesRuntimeGlobalOverrideAlongsidePackageFragments(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments'] = [
            ['prompt' => 'Runtime-injected instruction.', 'scope' => 'conversation'],
        ];
        try {
            $registry = $this->createRegistry([$this->packageAt('PackageOne')]);

            self::assertSame(
                ['Never use exclamation marks.', 'Runtime-injected instruction.'],
                $registry->getFragments(PromptFragmentScope::Conversation),
            );
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aim']['promptFragments']);
        }
    }
}
