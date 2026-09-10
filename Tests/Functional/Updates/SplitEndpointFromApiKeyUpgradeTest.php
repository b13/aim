<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Updates;

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Updates\SplitEndpointFromApiKeyUpgrade;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Migrating rows whose api_key held an endpoint URL, a credential, or both.
 */
final class SplitEndpointFromApiKeyUpgradeTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function aPlainEndpointMovesToItsOwnColumnAndLeavesNoCredentialBehind(): void
    {
        $uid = $this->insert('http://localhost:11434');

        $this->runWizard();

        $row = $this->row($uid);
        self::assertSame('http://localhost:11434', $row['endpoint']);
        self::assertSame('', $row['api_key']);
    }

    #[Test]
    public function aCredentialBearingUrlIsSplitAndTheCredentialEncrypted(): void
    {
        $uid = $this->insert('https://svc:s3cr3t-token@gateway.example.com/v1');

        $this->runWizard();

        $row = $this->row($uid);
        self::assertSame('https://svc@gateway.example.com/v1', $row['endpoint'], 'The user half identifies the account and is not the secret.');
        self::assertStringNotContainsString('s3cr3t-token', $row['endpoint']);
        self::assertTrue($this->get(ApiKeyEncryption::class)->isEncrypted($row['api_key']));
        self::assertSame('s3cr3t-token', $this->get(ApiKeyEncryption::class)->decrypt($row['api_key']));
    }

    #[Test]
    public function aLeftoverPlaintextCredentialIsEncryptedInPlace(): void
    {
        $uid = $this->insert('sk-proj-A1b2C3d4E5f6');

        $this->runWizard();

        $row = $this->row($uid);
        self::assertSame('', $row['endpoint']);
        self::assertSame('sk-proj-A1b2C3d4E5f6', $this->get(ApiKeyEncryption::class)->decrypt($row['api_key']));
    }

    #[Test]
    public function anAlreadyEncryptedRowIsLeftAloneAndTheWizardIsIdempotent(): void
    {
        $ciphertext = $this->get(ApiKeyEncryption::class)->encrypt('sk-already-stored');
        $uid = $this->insert($ciphertext);

        $subject = $this->get(SplitEndpointFromApiKeyUpgrade::class);
        self::assertFalse($subject->updateNecessary());

        $this->insert('http://localhost:11434');
        self::assertTrue($subject->updateNecessary());
        $subject->executeUpdate();

        self::assertSame($ciphertext, $this->row($uid)['api_key']);
        self::assertFalse($subject->updateNecessary(), 'The wizard is not idempotent.');
    }

    /**
     * The repository decrypts on read, so a migrated row has to come back out
     * as a usable endpoint plus a usable credential.
     */
    #[Test]
    public function aMigratedRowReadsBackAsSeparateEndpointAndCredential(): void
    {
        $uid = $this->insert('https://svc:s3cr3t-token@gateway.example.com/v1');
        $this->runWizard();

        $configuration = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertNotNull($configuration);
        self::assertSame('https://svc@gateway.example.com/v1', $configuration->endpoint);
        self::assertSame('s3cr3t-token', $configuration->apiKey);
    }

    /**
     * Until the wizard runs, an endpoint still sits in api_key. Reading a row
     * has to keep working, or an install breaks between the schema update and
     * someone clicking the wizard.
     */
    #[Test]
    public function anUnmigratedEndpointRowStillResolvesItsEndpoint(): void
    {
        $uid = $this->insert('http://localhost:11434');

        $configuration = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertNotNull($configuration);
        self::assertSame('http://localhost:11434', $configuration->endpoint);
    }

    private function runWizard(): void
    {
        self::assertTrue($this->get(SplitEndpointFromApiKeyUpgrade::class)->executeUpdate());
    }

    /**
     * An editor can open a legacy record between the schema update and the
     * wizard run. HideApiKey prefills the endpoint field with the legacy URL,
     * so a save keeps the leftover in api_key, and if the editor corrected the
     * endpoint while they were there, the wizard must not put the old host back.
     */
    #[Test]
    public function aDeliberatelyConfiguredEndpointSurvivesTheWizard(): void
    {
        $uid = $this->insert('http://stale-legacy-host:11434', 'http://corrected-host:11434');

        $this->runWizard();

        $row = $this->row($uid);
        self::assertSame('http://corrected-host:11434', $row['endpoint'], 'The configured endpoint was overwritten.');
        self::assertSame('', $row['api_key'], 'The leftover URL should just be dropped.');
    }

    /**
     * Same shape, but the leftover carries a credential: that half is still
     * worth keeping, encrypted, since it is the only copy.
     */
    #[Test]
    public function aCredentialIsKeptWhenTheEndpointIsAlreadyConfigured(): void
    {
        $uid = $this->insert('https://svc:s3cr3t-token@legacy.example.com/v1', 'https://corrected.example.com/v1');

        $this->runWizard();

        $row = $this->row($uid);
        self::assertSame('https://corrected.example.com/v1', $row['endpoint']);
        self::assertSame('s3cr3t-token', $this->get(ApiKeyEncryption::class)->decrypt($row['api_key']));
    }

    /**
     * The row above, asked about rather than executed. needsUpdate() used to
     * answer false for it while executeUpdate() would have taken the plaintext
     * credential out, so the wizard never offered itself and the credential
     * stayed readable in api_key for good. The panel only shows a wizard whose
     * updateNecessary() says yes.
     */
    #[Test]
    public function aCredentialLeftBehindAnAlreadyConfiguredEndpointIsReportedAsPending(): void
    {
        $this->insert('https://svc:s3cr3t-token@legacy.example.com/v1', 'https://corrected.example.com/v1');

        self::assertTrue($this->get(SplitEndpointFromApiKeyUpgrade::class)->updateNecessary());
    }

    /**
     * updateNecessary() is called by core before DatabaseUpdatedPrerequisite is
     * enforced, so it must not encrypt: a nonce per call would be wasted, and an
     * install with no encryption key would throw out of the wizard list.
     */
    #[Test]
    public function askingWhetherTheUpdateIsNecessaryDoesNotEncrypt(): void
    {
        $this->insert('sk-proj-A1b2C3d4E5f6');
        $subject = $this->get(SplitEndpointFromApiKeyUpgrade::class);

        self::assertTrue($subject->updateNecessary());
        self::assertTrue($subject->updateNecessary(), 'The answer must not depend on a previous call.');

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';
        self::assertTrue($subject->updateNecessary(), 'It must answer even with no encryption key configured.');
    }

    private function insert(string $apiKey, string $endpoint = ''): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => 'probe',
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'model' => 'test-model',
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array{api_key: string, endpoint: string}
     */
    private function row(int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['api_key', 'endpoint'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();
        self::assertNotFalse($row);

        return ['api_key' => (string)$row['api_key'], 'endpoint' => (string)$row['endpoint']];
    }
}
