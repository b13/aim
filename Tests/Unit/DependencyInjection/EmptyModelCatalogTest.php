<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection;

use B13\Aim\DependencyInjection\SymfonyAiCompilerPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A bridge was only registered once the pass had found models for it, either
 * in a catalog it could read or in one it could tell apart as needing runtime
 * context. symfony/ai-open-responses-platform is neither: its catalog is built
 * without arguments and starts out empty, because models are registered by
 * whoever configures the bridge and the provider falls back to a catalog
 * accepting any model name. The bridge was dropped, so the only way to reach a
 * self-hosted OpenAI-compatible endpoint never appeared in the provider list.
 * Reported as #35.
 */
final class EmptyModelCatalogTest extends TestCase
{
    private const FIXTURES = 'B13\\Aim\\Tests\\Unit\\DependencyInjection\\Fixtures\\';

    #[Test]
    public function aBridgeWhoseCatalogStartsEmptyIsRegistered(): void
    {
        $bridge = $this->buildBridge('symfony/ai-open-responses-platform', self::FIXTURES . 'EmptyCatalog');

        self::assertIsArray($bridge, 'The bridge was dropped for having no models to offer yet.');
        self::assertSame('openresponses', $bridge['identifier']);
        self::assertSame([], $bridge['models'], 'Models come from the configured endpoint, not from the catalog.');
    }

    /**
     * The record holds the endpoint, and the model list is fetched from it
     * later. Passing it as the credential instead would send the URL as a
     * bearer token and leave the bridge without a host to talk to.
     */
    #[Test]
    public function theEndpointIsRecognisedAsTheArgumentTheBridgeWants(): void
    {
        $bridge = $this->buildBridge('symfony/ai-open-responses-platform', self::FIXTURES . 'EmptyCatalog');

        self::assertIsArray($bridge);
        self::assertSame('endpoint', $bridge['factoryParam']);
    }

    /**
     * Why the catalog could not be read says nothing about whether the package
     * is a bridge, and a bridge missing from the list is harder to explain than
     * one whose model field stays empty.
     */
    #[Test]
    public function aBridgeWhoseCatalogRefusesToBeBuiltIsStillRegistered(): void
    {
        $bridge = $this->buildBridge('acme/ai-brittle-platform', self::FIXTURES . 'ThrowingCatalog');

        self::assertIsArray($bridge);
        self::assertSame([], $bridge['models']);
    }

    /**
     * Symfony AI builds no provider without a catalog, so a package carrying
     * the bridge package type and nothing else is not one.
     */
    #[Test]
    public function aPackageWithoutAnyCatalogIsNotABridge(): void
    {
        $bridge = $this->buildBridge('acme/ai-pretend-platform', self::FIXTURES . 'Catalogless');

        self::assertNull($bridge);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildBridge(string $packageName, string $namespace): ?array
    {
        $method = new \ReflectionMethod(SymfonyAiCompilerPass::class, 'buildBridgeDefinition');

        return $method->invoke(new SymfonyAiCompilerPass(), [
            'name' => $packageName,
            'namespace' => $namespace,
        ]);
    }
}
