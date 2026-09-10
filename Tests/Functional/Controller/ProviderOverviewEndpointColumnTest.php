<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Controller;

use B13\Aim\Domain\Repository\ProviderConfigurationDemand;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The overview column needs the resolved endpoint, so a row the split migration
 * has not reached still shows its URL rather than an empty cell.
 */
final class ProviderOverviewEndpointColumnTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function bothMigratedAndUnmigratedRowsResolveAnEndpoint(): void
    {
        $migrated = $this->insert(['endpoint' => 'http://migrated:11434', 'api_key' => '']);
        $legacy = $this->insert(['endpoint' => '', 'api_key' => 'http://legacy:11434']);
        $hosted = $this->insert(['endpoint' => '', 'api_key' => 'sk-secret']);

        $byUid = [];
        foreach ($this->get(ProviderConfigurationRepository::class)->findAll() as $configuration) {
            $byUid[$configuration->uid] = $configuration->endpoint;
        }

        self::assertSame('http://migrated:11434', $byUid[$migrated]);
        self::assertSame('http://legacy:11434', $byUid[$legacy], 'An unmigrated row would show an empty cell.');
        self::assertSame('', $byUid[$hosted], 'A hosted provider has no endpoint of its own.');
    }

    #[Test]
    public function endpointIsAnAcceptedSortFieldAndAnythingElseIsRejected(): void
    {
        self::assertContains('endpoint', ProviderConfigurationDemand::getOrderFields());

        $demand = new ProviderConfigurationDemand(1, 'endpoint');
        self::assertSame('endpoint', $demand->getOrderField());

        $injected = new ProviderConfigurationDemand(1, 'endpoint; DROP TABLE tx_aim_configuration');
        self::assertNotSame('endpoint; DROP TABLE tx_aim_configuration', $injected->getOrderField());
    }

    #[Test]
    public function sortingByEndpointReturnsRowsWithoutError(): void
    {
        $this->insert(['endpoint' => 'http://b:11434', 'api_key' => '']);
        $this->insert(['endpoint' => 'http://a:11434', 'api_key' => '']);

        $rows = $this->get(ProviderConfigurationRepository::class)
            ->findByDemand(new ProviderConfigurationDemand(1, 'endpoint', 'ASC'));

        self::assertCount(2, $rows);
        self::assertSame('http://a:11434', $rows[0]->endpoint);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function insert(array $overrides): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge([
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => 'probe',
            'model' => 'test-model',
        ], $overrides));

        return (int)$connection->lastInsertId();
    }
}
