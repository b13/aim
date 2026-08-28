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

use B13\Aim\Crypto\ApiKeyEncryption;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * On an update, DataHandler captures the sys_history diff in
 * compareFieldArrayWithCurrentAndUnset() before it calls
 * processDatamap_postProcessFieldArray(). Encrypting only there leaves the
 * plaintext key in the record history. Inserts are not affected.
 *
 * The unit test can't show this, it calls the hook directly.
 */
final class EncryptApiKeyHistoryTest extends FunctionalTestCase
{
    private const NEW_KEY = 'sk-history-leak-probe';

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
    public function changingTheApiKeyDoesNotRecordThePlaintextInHistory(): void
    {
        $uid = $this->process(['NEW1' => [
            'pid' => 0,
            'ai_provider' => 'openai',
            'title' => 'probe',
            'api_key' => 'sk-initial',
        ]]);

        $this->process([$uid => ['api_key' => self::NEW_KEY]]);

        $encryption = new ApiKeyEncryption();
        $stored = $this->storedApiKey($uid);
        self::assertTrue($encryption->isEncrypted($stored), 'The api_key column is not encrypted.');
        self::assertSame(self::NEW_KEY, $encryption->decrypt($stored), 'The api_key was encrypted twice or not replaced.');

        self::assertStringNotContainsString(self::NEW_KEY, $this->historyData($uid), 'sys_history contains the api_key in plaintext.');
    }

    /**
     * @param array<int|string, array<string, mixed>> $records
     */
    private function process(array $records): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_aim_configuration' => $records], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler rejected the datamap: ' . implode('; ', $dataHandler->errorLog));

        return (int)($dataHandler->substNEWwithIDs['NEW1'] ?? array_key_first($records));
    }

    private function storedApiKey(int $uid): string
    {
        $row = $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration')
            ->select(['api_key'], 'tx_aim_configuration', ['uid' => $uid])
            ->fetchAssociative();
        self::assertNotFalse($row, 'The configuration record does not exist.');

        return (string)$row['api_key'];
    }

    private function historyData(int $uid): string
    {
        $rows = $this->getConnectionPool()->getConnectionForTable('sys_history')
            ->select(['history_data'], 'sys_history', ['tablename' => 'tx_aim_configuration', 'recuid' => $uid])
            ->fetchAllAssociative();
        self::assertNotEmpty($rows, 'DataHandler did not write a sys_history entry.');

        return implode("\n", array_column($rows, 'history_data'));
    }
}
