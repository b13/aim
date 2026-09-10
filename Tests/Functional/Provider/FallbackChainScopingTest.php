<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Provider;

use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Exception\ProviderNotFoundException;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Registry\AiProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Which configurations a request may reach: the one the caller resolved heads
 * the chain, and a retry never reaches one the operator excluded.
 */
final class FallbackChainScopingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'testprovider',
            name: 'Test Provider',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [TextGenerationCapableInterface::class],
            serviceName: 'test.provider',
            container: $this->createMock(ContainerInterface::class),
        ));
    }

    #[Test]
    public function anExplicitlyResolvedProviderHeadsTheChain(): void
    {
        $defaultUid = $this->createConfiguration('the-default', ['default' => 1]);
        $requestedUid = $this->createConfiguration('the-requested');

        $chain = $this->subject()->buildFallbackChain(
            TextGenerationCapableInterface::class,
            $this->resolve($requestedUid),
        );

        self::assertSame($requestedUid, $chain->getPrimary()->configuration->uid, 'The requested configuration did not head the chain.');
        self::assertSame([$defaultUid], $this->fallbackUids($chain), 'The default should be a fallback, not the primary.');
    }

    #[Test]
    public function withoutAnExplicitPrimaryTheDefaultStillHeadsTheChain(): void
    {
        $this->createConfiguration('a-non-default');
        $defaultUid = $this->createConfiguration('the-default', ['default' => 1]);

        $chain = $this->subject()->buildFallbackChain(TextGenerationCapableInterface::class);

        self::assertSame($defaultUid, $chain->getPrimary()->configuration->uid);
    }

    #[Test]
    public function aPinnedPrimaryGetsNoFallbacksAtAll(): void
    {
        $pinnedUid = $this->createConfiguration('local-ollama', ['rerouting_allowed' => 0]);
        $this->createConfiguration('cloud-provider', ['default' => 1]);

        $chain = $this->subject()->buildFallbackChain(
            TextGenerationCapableInterface::class,
            $this->resolve($pinnedUid),
        );

        self::assertSame($pinnedUid, $chain->getPrimary()->configuration->uid);
        self::assertSame([], $chain->getFallbacks(), 'A pinned configuration must not fall back anywhere.');
        self::assertCount(1, $chain);
    }

    #[Test]
    public function aConfigurationThatRefusesReroutedRequestsIsNotOfferedAsAFallback(): void
    {
        $defaultUid = $this->createConfiguration('cloud-provider', ['default' => 1]);
        $this->createConfiguration('hr-only-model', ['accepts_rerouted_requests' => 0]);
        $openUid = $this->createConfiguration('another-cloud-provider');

        $chain = $this->subject()->buildFallbackChain(
            TextGenerationCapableInterface::class,
            $this->resolve($defaultUid),
        );

        self::assertSame([$openUid], $this->fallbackUids($chain), 'General traffic was offered a model reserved for particular content.');
    }

    #[Test]
    public function pinningAConfigurationDoesNotStopItReceivingOtherTraffic(): void
    {
        $defaultUid = $this->createConfiguration('cloud-provider', ['default' => 1]);
        $pinnedButOpenUid = $this->createConfiguration('local-ollama', [
            'rerouting_allowed' => 0,
            'accepts_rerouted_requests' => 1,
        ]);

        $chain = $this->subject()->buildFallbackChain(
            TextGenerationCapableInterface::class,
            $this->resolve($defaultUid),
        );

        self::assertSame([$pinnedButOpenUid], $this->fallbackUids($chain), 'rerouting_allowed is still being read in both directions.');
    }

    #[Test]
    public function fallbacksTheUserMayNotUseAreLeftOut(): void
    {
        $this->createBackendUserInGroup(1);
        $primaryUid = $this->createConfiguration('open-to-everyone', ['default' => 1]);
        $allowedUid = $this->createConfiguration('for-my-group', ['be_groups' => '1']);
        $this->createConfiguration('for-another-group', ['be_groups' => '2']);

        $chain = $this->subject()->buildFallbackChain(
            TextGenerationCapableInterface::class,
            $this->resolve($primaryUid),
        );

        self::assertSame([$allowedUid], $this->fallbackUids($chain), 'A configuration outside the user\'s groups was offered as a fallback.');
    }

    /**
     * The model field is not required, so a configuration can exist without one.
     * It cannot serve a request, so resolution has to pass it over.
     */
    #[Test]
    public function aConfigurationWithoutAModelIsNeverResolved(): void
    {
        $this->createConfiguration('not-configured-yet', ['default' => 1, 'model' => '']);

        $this->expectException(ProviderNotFoundException::class);
        $this->subject()->buildFallbackChain(TextGenerationCapableInterface::class);
    }

    #[Test]
    public function aModellessDefaultDoesNotShadowAUsableConfiguration(): void
    {
        $this->createConfiguration('not-configured-yet', ['default' => 1, 'model' => '']);
        $usable = $this->createConfiguration('ready');

        $chain = $this->subject()->buildFallbackChain(TextGenerationCapableInterface::class);

        self::assertSame($usable, $chain->getPrimary()->configuration->uid);
        self::assertSame([], $this->fallbackUids($chain), 'A configuration with no model must not be a fallback either.');
    }

    private function subject(): ProviderResolver
    {
        return $this->get(ProviderResolver::class);
    }

    private function resolve(int $uid): ResolvedProvider
    {
        return $this->subject()->resolveForCapability(TextGenerationCapableInterface::class, $uid);
    }

    /**
     * @return list<int>
     */
    private function fallbackUids(\B13\Aim\Provider\FallbackChain $chain): array
    {
        return array_map(
            static fn(ResolvedProvider $provider): int => $provider->configuration->uid,
            $chain->getFallbacks(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createConfiguration(string $title, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration');
        $connection->insert('tx_aim_configuration', array_merge([
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => $title,
            'api_key' => 'sk-' . $title,
            'model' => 'test-model',
            'default' => 0,
            'rerouting_allowed' => 1,
            'accepts_rerouted_requests' => 1,
            'be_groups' => '',
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function createBackendUserInGroup(int $groupUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => $groupUid, 'title' => 'group ' . $groupUid]);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'editor', 'admin' => 0, 'usergroup' => (string)$groupUid]);
        $this->setUpBackendUser(1);
    }
}
