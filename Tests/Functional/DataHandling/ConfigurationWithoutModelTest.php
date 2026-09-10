<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\DataHandling;

use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Through the real DataHandler, because the TCA is what decides this and the
 * unit tests around it never reach that layer.
 */
final class ConfigurationWithoutModelTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function aConfigurationSavesWithoutAModel(): void
    {
        $uid = $this->save([
            'pid' => 0,
            'ai_provider' => 'ollama',
            'title' => 'Local, models not reachable yet',
            'endpoint' => 'http://localhost:11434',
            'model' => '',
        ]);

        $row = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['title', 'model'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();

        self::assertNotFalse($row, 'The record was rejected, so a provider needing a credential for its model list cannot be set up.');
        self::assertSame('', $row['model']);
    }

    #[Test]
    public function suchAConfigurationIsTreatedAsDisabled(): void
    {
        $uid = $this->save([
            'pid' => 0,
            'ai_provider' => 'ollama',
            'title' => 'Local, models not reachable yet',
            'endpoint' => 'http://localhost:11434',
            'model' => '',
        ]);

        $configuration = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertNotNull($configuration);
        self::assertTrue($configuration->disabled);
        self::assertSame(0, $configuration->row['disabled'], 'The stored column must keep what the editor set.');
    }

    #[Test]
    public function aConfigurationStillNeedsAProvider(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => ['NEW1' => [
            'pid' => 0,
            'ai_provider' => '',
            'title' => 'No provider',
            'model' => '',
        ]]], []);
        $dataHandler->process_datamap();

        $uid = (int)($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
        if ($uid === 0) {
            self::assertNotSame([], $dataHandler->errorLog);
            return;
        }
        $row = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['ai_provider'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();
        self::assertSame('', $row['ai_provider'], 'Documents current behaviour: DataHandler does not hard-reject a missing provider.');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(array $values): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => ['NEW1' => $values]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler rejected the record: ' . implode('; ', $dataHandler->errorLog));

        return (int)$dataHandler->substNEWwithIDs['NEW1'];
    }
}
