<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Command;

use B13\Aim\Command\RotateApiKeys;
use B13\Aim\Crypto\ApiKeyEncryption;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RotateApiKeysTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';
    private const OLD_KEY = 'old-system-key-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NEW_KEY = 'new-system-key-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function commandIsRegisteredInTheConsoleRegistry(): void
    {
        $registry = $this->get(CommandRegistry::class);
        self::assertTrue($registry->has('aim:rotateApiKeys'));
        self::assertInstanceOf(RotateApiKeys::class, $registry->get('aim:rotateApiKeys'));
    }

    #[Test]
    public function rotatesEncryptedRowsFromOldKeyToCurrent(): void
    {
        $encryption = $this->get(ApiKeyEncryption::class);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::OLD_KEY;
        $uid = $this->insertRow($encryption->encrypt('sk-real-secret'));

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::NEW_KEY;
        // The old ciphertext must NOT decrypt with the new key
        $this->assertThrows(fn() => $encryption->decrypt($this->fetchRawApiKey($uid)));

        $exit = $this->runCommand(['--old-key' => self::OLD_KEY]);
        self::assertSame(0, $exit);

        // After rotation, the value decrypts with the current key
        self::assertSame('sk-real-secret', $encryption->decrypt($this->fetchRawApiKey($uid)));
    }

    #[Test]
    public function isIdempotentOnSecondRun(): void
    {
        $encryption = $this->get(ApiKeyEncryption::class);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::OLD_KEY;
        $uid = $this->insertRow($encryption->encrypt('sk-real-secret'));

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::NEW_KEY;

        self::assertSame(0, $this->runCommand(['--old-key' => self::OLD_KEY]));
        $afterFirst = $this->fetchRawApiKey($uid);

        $tester = $this->runCommandAndReturnTester(['--old-key' => self::OLD_KEY]);
        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Nothing to rotate', $display);
        self::assertStringContainsString('1 row(s) already use the current system key', $display);

        // Same ciphertext (no double re-encryption)
        self::assertSame($afterFirst, $this->fetchRawApiKey($uid));
        self::assertSame('sk-real-secret', $encryption->decrypt($afterFirst));
    }

    #[Test]
    public function endpointUrlsAreSkippedAndReportedDistinctly(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::NEW_KEY;
        $uid = $this->insertRow('http://host.docker.internal:11434');

        $tester = $this->runCommandAndReturnTester(['--old-key' => self::OLD_KEY]);
        self::assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Nothing to rotate', $display);
        self::assertStringContainsString('1 row(s) are not encrypted', $display);
        self::assertStringNotContainsString('already use the current system key', $display);
        self::assertSame('http://host.docker.internal:11434', $this->fetchRawApiKey($uid));
    }

    #[Test]
    public function abortsAndReportsWhenOldKeyIsWrong(): void
    {
        $encryption = $this->get(ApiKeyEncryption::class);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::OLD_KEY;
        $uid = $this->insertRow($encryption->encrypt('sk-real-secret'));
        $beforeRotation = $this->fetchRawApiKey($uid);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::NEW_KEY;

        $tester = $this->runCommandAndReturnTester(['--old-key' => 'definitely-not-the-old-key']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('cannot be decrypted', $tester->getDisplay());
        self::assertStringContainsString('Aborting without writes', $tester->getDisplay());

        // Row was not touched
        self::assertSame($beforeRotation, $this->fetchRawApiKey($uid));
    }

    #[Test]
    public function failsWithoutOldKey(): void
    {
        $tester = $this->runCommandAndReturnTester([]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--old-key is required', $tester->getDisplay());
    }

    #[Test]
    public function dryRunDoesNotWrite(): void
    {
        $encryption = $this->get(ApiKeyEncryption::class);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::OLD_KEY;
        $uid = $this->insertRow($encryption->encrypt('sk-real-secret'));
        $beforeRotation = $this->fetchRawApiKey($uid);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::NEW_KEY;

        $tester = $this->runCommandAndReturnTester([
            '--old-key' => self::OLD_KEY,
            '--dry-run' => true,
        ]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('[dry-run]', $tester->getDisplay());
        self::assertSame($beforeRotation, $this->fetchRawApiKey($uid));
    }

    private function runCommand(array $options): int
    {
        return $this->runCommandAndReturnTester($options)->getStatusCode();
    }

    private function runCommandAndReturnTester(array $options): CommandTester
    {
        $tester = new CommandTester($this->get(RotateApiKeys::class));
        $tester->execute($options);
        return $tester;
    }

    private function insertRow(string $apiKey): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'test',
            'title' => 'Test',
            'api_key' => $apiKey,
            'model' => 'test-model',
        ]);
        return (int)$connection->lastInsertId();
    }

    private function fetchRawApiKey(int $uid): string
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        return (string)$qb->select('api_key')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    private function assertThrows(callable $callable): void
    {
        try {
            $callable();
        } catch (\Throwable) {
            return;
        }
        self::fail('Expected an exception but none was thrown.');
    }
}
