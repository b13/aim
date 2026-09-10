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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Bridge discovery matches on the `symfony-ai-platform` Composer package type,
 * so a bridge from any vendor is found. The identifier was still derived by
 * stripping a hardcoded `symfony/ai-` prefix, so for those very packages the
 * vendor prefix survived, slash included, and that value is a DI service id,
 * the value persisted in tx_aim_configuration and the key every registry
 * lookup uses. Reported as #32.
 */
final class BridgeIdentifierTest extends TestCase
{
    private const FIXTURES = 'B13\\Aim\\Tests\\Unit\\DependencyInjection\\Fixtures\\';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function symfonyPackages(): array
    {
        return [
            // The derivation is correct by construction for these, and the
            // bridge is deliberately not asked: preferring a declared name
            // here could change an identifier installs already have stored.
            'openai' => ['symfony/ai-open-ai-platform', 'openai', 'Symfony AI: OpenAI'],
            'anthropic' => ['symfony/ai-anthropic-platform', 'anthropic', 'Symfony AI: Anthropic'],
        ];
    }

    #[Test]
    #[DataProvider('symfonyPackages')]
    public function aSymfonyBridgeKeepsTheIdentifierItAlreadyHad(string $package, string $identifier, string $name): void
    {
        $bridge = $this->buildBridge($package, self::FIXTURES . 'ThirdParty');

        self::assertSame($identifier, $bridge['identifier']);
        self::assertSame($name, $bridge['name']);
    }

    #[Test]
    public function aThirdPartyBridgeIsAskedForItsOwnName(): void
    {
        $bridge = $this->buildBridge('t3ppy/symfony-ai-platform', self::FIXTURES . 'ThirdParty');

        self::assertSame('t3ppy', $bridge['identifier'], 'The vendor prefix survived into the identifier.');
        self::assertSame('Symfony AI: T3ppy', $bridge['name']);
    }

    /**
     * A factory that validates its credential eagerly cannot be built at
     * compile time, and that must cost nothing: the derived value stays, which
     * is what the install has today.
     */
    #[Test]
    public function aFactoryThatRefusesToBeBuiltFallsBackToTheDerivedValue(): void
    {
        $bridge = $this->buildBridge('t3ppy/symfony-ai-platform', self::FIXTURES . 'Refusing');

        self::assertSame('t3ppy/symfonyai', $bridge['identifier']);
    }

    /**
     * There is no empty value to pass for a required argument that is not a
     * string, and guessing one is worse than not asking.
     */
    #[Test]
    public function aFactoryWithARequiredNonStringArgumentIsNotCalled(): void
    {
        $bridge = $this->buildBridge('t3ppy/symfony-ai-platform', self::FIXTURES . 'NonString');

        self::assertSame('t3ppy/symfonyai', $bridge['identifier']);
    }

    /**
     * The answer has to be usable as an identifier. A bridge handing out
     * something shaped like a package name would only move the problem into
     * the service id and the database column.
     */
    #[Test]
    public function aDeclaredNameThatIsNotUsableAsAnIdentifierIsRefused(): void
    {
        $bridge = $this->buildBridge('acme/ai-cool-platform', self::FIXTURES . 'PackageShaped');

        self::assertSame('acme/aicool', $bridge['identifier']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBridge(string $packageName, string $namespace): array
    {
        $method = new \ReflectionMethod(SymfonyAiCompilerPass::class, 'buildBridgeDefinition');
        $bridge = $method->invoke(new SymfonyAiCompilerPass(), [
            'name' => $packageName,
            'namespace' => $namespace,
        ]);
        self::assertIsArray($bridge, 'The fixture factory was not discovered at all.');

        return $bridge;
    }

    /**
     * The cheap half of the probe, and the one that matters in practice: every
     * bridge measured so far declares `string $name = '...'` on its factory, so
     * the name is read by reflection and no bridge code runs while the
     * container is compiled. This fixture also refuses to be built, the way
     * symfony/ai-open-ai-platform does with an empty credential, so nothing but
     * reflection can answer for it.
     */
    #[Test]
    public function aNameDeclaredInTheFactorySignatureIsReadWithoutBuildingAnything(): void
    {
        $bridge = $this->buildBridge('acme/ai-cloud-platform', self::FIXTURES . 'DeclaredName');

        self::assertSame('acmecloud', $bridge['identifier']);
        self::assertSame('Symfony AI: Acmecloud', $bridge['name']);
    }
}
