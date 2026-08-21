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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies the round-trip between an encrypted column and a decrypted
 * ProviderConfiguration value object, plus the legacy-plaintext upgrade.
 */
final class ApiKeyEncryptionPersistenceTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    // Without EXT:install loaded, nothing ever builds the ServiceLocator
    // that #[UpgradeWizard]-tagged services (including EncryptApiKeysUpgrade)
    // get collected into - and without that, the testing framework's
    // "private service reachable via some other service's own dependency"
    // heuristic never picks EncryptApiKeysUpgrade up either, so
    // $this->get(EncryptApiKeysUpgrade::class) below would fail to resolve.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

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
    public function isTaggedWithTheIdentifierTheInstallToolRegistryDiscoversItBy(): void
    {
        // What actually makes TYPO3 discover this wizard - not going through
        // UpgradeWizardRegistry itself: core marks it @internal and never
        // exposes it as a public service, and its constructor takes a
        // ServiceLocator built by a compiler pass specifically for its
        // #[AutowireLocator] parameter - something no test can construct by
        // hand without the assertion becoming tautological (a hand-built
        // locator would "discover" the wizard regardless of whether the
        // real attribute-based tagging works at all). Also namespaced
        // differently between v12/v13 (TYPO3\CMS\Install\Updates\...) and
        // v14 (moved into TYPO3\CMS\Core\Upgrades\...), unlike
        // UpgradeWizardInterface/the UpgradeWizard attribute itself, which
        // both stay at their original TYPO3\CMS\Install\* namespace via a
        // class-alias-loader alias on v14 too.
        $attributes = (new \ReflectionClass(EncryptApiKeysUpgrade::class))->getAttributes(UpgradeWizard::class);
        self::assertCount(1, $attributes);
        self::assertSame('aimEncryptApiKeys', $attributes[0]->newInstance()->identifier);
    }

    #[Test]
    public function upgradeWizardEncryptsLegacyPlaintextRows(): void
    {
        $uid = $this->insertRow('sk-legacy-from-old-version');

        $wizard = $this->get(EncryptApiKeysUpgrade::class);
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

        $wizard = $this->get(EncryptApiKeysUpgrade::class);
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

        $wizard = $this->get(EncryptApiKeysUpgrade::class);
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
