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
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * `provider:model` naming a model no record has borrows a stored
 * configuration's credential and endpoint. Borrowing the credential means
 * inheriting the restrictions that configuration was given, or naming an
 * unconfigured model would be a way around them.
 */
final class NotationOverrideGovernanceTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

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
    public function theSourceConfigurationsRestrictionsAreInherited(): void
    {
        $this->createConfiguration([
            'be_groups' => '7',
            'privacy_level' => 'none',
            'rerouting_allowed' => 0,
            'accepts_rerouted_requests' => 0,
            'api_key' => 'sk-restricted-key',
            'endpoint' => 'https://gateway.example.com/v1',
        ]);

        $resolved = $this->get(ProviderResolver::class)
            ->resolveByString('testprovider:some-model-with-no-record', TextGenerationCapableInterface::class);
        $configuration = $resolved->configuration;

        // It really is the borrowed credential and endpoint.
        self::assertSame('sk-restricted-key', $configuration->apiKey);
        self::assertSame('https://gateway.example.com/v1', $configuration->endpoint);
        self::assertSame('some-model-with-no-record', $configuration->model);

        // And the restrictions came with them.
        self::assertSame('7', $configuration->beGroups, 'The group restriction was dropped.');
        self::assertSame('none', $configuration->privacyLevel, 'The privacy level fell back to standard.');
        self::assertFalse($configuration->reroutingAllowed, 'The pin was dropped.');
        self::assertFalse($configuration->acceptsReroutedRequests, 'The receiving restriction was dropped.');
    }

    /**
     * Nothing to inherit when the caller supplies its own credential: there is
     * no stored configuration behind it, so the defaults are correct there.
     */
    #[Test]
    public function anExplicitlyPassedKeyStillProducesAnUnrestrictedConfiguration(): void
    {
        $configuration = $this->get(ProviderResolver::class)
            ->resolveByString('testprovider:any-model', TextGenerationCapableInterface::class, 'sk-caller-key')
            ->configuration;

        self::assertSame('sk-caller-key', $configuration->apiKey);
        self::assertSame('', $configuration->beGroups);
        self::assertSame('standard', $configuration->privacyLevel);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createConfiguration(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge([
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => 'restricted',
            'model' => 'the-configured-model',
            'default' => 1,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }
}
