<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Crypto;

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Updates\EncryptApiKeysUpgrade;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies the round-trip between an encrypted column and a decrypted
 * ProviderConfiguration value object, plus the legacy-plaintext upgrade.
 */
final class ApiKeyEncryptionPersistenceTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
    }

    #[Test]
    public function repositoryDecryptsApiKeyOnRead(): void
    {
        $encryption = $this->get(ApiKeyEncryption::class);
        $uid = $this->insertRow($encryption->encrypt('sk-plaintext-secret'));

        $config = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertNotNull($config);
        self::assertSame('sk-plaintext-secret', $config->apiKey);
    }

    #[Test]
    public function repositoryReturnsLegacyPlaintextUnchanged(): void
    {
        $uid = $this->insertRow('sk-legacy-plaintext');

        $config = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertNotNull($config);
        self::assertSame('sk-legacy-plaintext', $config->apiKey);
    }

    #[Test]
    public function upgradeWizardIsRegisteredInTheInstallToolRegistry(): void
    {
        $registry = $this->get(UpgradeWizardRegistry::class);
        self::assertTrue($registry->hasUpgradeWizard('aimEncryptApiKeys'));
        self::assertInstanceOf(EncryptApiKeysUpgrade::class, $registry->getUpgradeWizard('aimEncryptApiKeys'));
    }

    #[Test]
    public function upgradeWizardEncryptsLegacyPlaintextRows(): void
    {
        $uid = $this->insertRow('sk-legacy-from-old-version');

        $wizard = $this->get(UpgradeWizardRegistry::class)->getUpgradeWizard('aimEncryptApiKeys');
        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertFalse($wizard->updateNecessary());

        self::assertStringStartsWith(ApiKeyEncryption::PREFIX_ANY, $this->fetchRawApiKey($uid));

        $config = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);
        self::assertNotNull($config);
        self::assertSame('sk-legacy-from-old-version', $config->apiKey);
    }

    #[Test]
    public function upgradeWizardLeavesEndpointUrlsAsPlaintext(): void
    {
        $uid = $this->insertRow('http://host.docker.internal:11434');

        $wizard = $this->get(UpgradeWizardRegistry::class)->getUpgradeWizard('aimEncryptApiKeys');
        self::assertFalse($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame('http://host.docker.internal:11434', $this->fetchRawApiKey($uid));

        $config = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);
        self::assertNotNull($config);
        self::assertSame('http://host.docker.internal:11434', $config->apiKey);
    }

    #[Test]
    public function upgradeWizardLeavesAlreadyEncryptedRowsAlone(): void
    {
        $encryption = $this->get(ApiKeyEncryption::class);
        $uid = $this->insertRow($encryption->encrypt('sk-already-encrypted'));
        $before = $this->fetchRawApiKey($uid);

        $wizard = $this->get(UpgradeWizardRegistry::class)->getUpgradeWizard('aimEncryptApiKeys');
        self::assertFalse($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame($before, $this->fetchRawApiKey($uid));
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
}
