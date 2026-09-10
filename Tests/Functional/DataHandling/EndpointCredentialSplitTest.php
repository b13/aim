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
use B13\Aim\Hooks\EncryptApiKey;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A credential typed into the endpoint field must end up stored the same way a
 * migrated one is: encrypted in api_key, with only the user half left in the
 * URL. Storing the URL whole would keep it in a plaintext column and show it in
 * the backend, including the overview's endpoint column.
 */
final class EndpointCredentialSplitTest extends FunctionalTestCase
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
    public function aTypedCredentialIsTakenOutOfTheUrlAndEncrypted(): void
    {
        $uid = $this->save(['endpoint' => 'https://svc:s3cret@gw.example.com/v1', 'api_key' => '']);

        $row = $this->row($uid);
        self::assertSame('https://svc@gw.example.com/v1', $row['endpoint'], 'The credential is still in the stored URL.');
        self::assertTrue($this->get(ApiKeyEncryption::class)->isEncrypted($row['api_key']));
        self::assertSame('s3cret', $this->get(ApiKeyEncryption::class)->decrypt($row['api_key']));
    }

    #[Test]
    public function theCredentialGoesBackIntoTheUrlForARequest(): void
    {
        $uid = $this->save(['endpoint' => 'https://svc:s3cret@gw.example.com/v1', 'api_key' => '']);

        $configuration = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertTrue($configuration->expectsCredentialInUrl());
        self::assertSame('https://svc:s3cret@gw.example.com/v1', $configuration->getRequestEndpoint());
        self::assertSame('https://svc@gw.example.com/v1', $configuration->endpoint, 'The stored value must stay clean.');
    }

    #[Test]
    public function anEndpointWithoutACredentialIsUntouched(): void
    {
        $uid = $this->save(['endpoint' => 'http://localhost:11434', 'api_key' => '']);

        $configuration = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertSame('http://localhost:11434', $this->row($uid)['endpoint']);
        self::assertFalse($configuration->expectsCredentialInUrl());
        self::assertSame('http://localhost:11434', $configuration->getRequestEndpoint());
    }

    /**
     * Someone who fills in both fields meant what they typed in the key field.
     */
    #[Test]
    public function anExplicitApiKeyIsNotOverwrittenByOneInTheUrl(): void
    {
        // Two different passwords, so this is the conflicting case and the save
        // reports which one it kept. See
        // fillingInBothPasswordsReportsThatTheUrlOneWasDiscarded().
        $uid = $this->save(['endpoint' => 'https://svc:from-url@gw/v1', 'api_key' => 'from-the-field'], expectWarning: true);

        self::assertSame('from-the-field', $this->get(ApiKeyEncryption::class)->decrypt($this->row($uid)['api_key']));
        self::assertSame('https://svc@gw/v1', $this->row($uid)['endpoint']);
    }

    #[Test]
    public function aBearerStyleSetupStillSendsAHeaderRatherThanAUrlCredential(): void
    {
        $uid = $this->save(['endpoint' => 'https://gw.example.com/api', 'api_key' => 'sk-bearer']);

        $configuration = $this->get(ProviderConfigurationRepository::class)->findByUid($uid);

        self::assertFalse($configuration->expectsCredentialInUrl());
        self::assertSame('https://gw.example.com/api', $configuration->getRequestEndpoint());
        self::assertSame('sk-bearer', $configuration->apiKey);
    }

    /**
     * @param array<string, string> $values
     */
    /**
     * A row the split migration has not reached keeps its endpoint in api_key.
     * HideApiKey shows that URL in the endpoint field without its password, so
     * the save has to take the leftover out of api_key itself: otherwise the
     * old URL stays there in plain text and, once the editor corrects the
     * endpoint, goes out as a bearer token.
     */
    #[Test]
    public function savingALegacyRowMovesItsCredentialOutOfApiKey(): void
    {
        $uid = $this->insertLegacyRow('https://svc:s3cr3t-token@legacy.example.com/v1');

        // What the edit form submits: the endpoint as HideApiKey shows it, and
        // an untouched (blank) key field.
        $this->update($uid, ['endpoint' => 'https://svc@legacy.example.com/v1', 'api_key' => '']);

        $row = $this->row($uid);
        self::assertSame('https://svc@legacy.example.com/v1', $row['endpoint']);
        self::assertSame('s3cr3t-token', $this->get(ApiKeyEncryption::class)->decrypt($row['api_key']));
        self::assertStringNotContainsString('s3cr3t-token', $row['endpoint']);
    }

    #[Test]
    public function savingALegacyRowWithNoCredentialJustClearsApiKey(): void
    {
        $uid = $this->insertLegacyRow('http://localhost:11434');

        $this->update($uid, ['endpoint' => 'http://localhost:11434', 'api_key' => '']);

        $row = $this->row($uid);
        self::assertSame('http://localhost:11434', $row['endpoint']);
        self::assertSame('', $row['api_key'], 'The leftover URL should not stay in the credential column.');
    }

    /**
     * A stored key that is a real credential must still survive a blank
     * resubmission, which is the whole point of "empty means keep".
     */
    #[Test]
    public function aBlankResubmissionStillKeepsARealStoredKey(): void
    {
        $uid = $this->save(['endpoint' => 'https://gateway.example.com/v1', 'api_key' => 'sk-real-key']);
        $stored = $this->row($uid)['api_key'];

        $this->update($uid, ['endpoint' => 'https://gateway.example.com/v1', 'api_key' => '']);

        self::assertSame($stored, $this->row($uid)['api_key']);
    }

    /**
     * Both fields filled in with different passwords means one of them is
     * discarded. The API key field winning is intended, but an editor who left
     * an old password in the URL had no way to see which one survived.
     */
    #[Test]
    public function fillingInBothPasswordsReportsThatTheUrlOneWasDiscarded(): void
    {
        $errors = $this->saveCollectingErrors([
            'endpoint' => 'https://svc:old-url-password@gw.example.com/v1',
            'api_key' => 'the-typed-password',
        ]);

        self::assertCount(1, $errors, 'The editor was not told that a password was dropped.');
        self::assertStringContainsString('API key field was used', $errors[0]);
        self::assertStringNotContainsString('old-url-password', $errors[0], 'The message must not quote the secret.');
        self::assertStringNotContainsString('the-typed-password', $errors[0]);
    }

    /**
     * Same password in both places is not a conflict, just redundancy.
     */
    #[Test]
    public function fillingInTheSamePasswordTwiceIsSilent(): void
    {
        $errors = $this->saveCollectingErrors([
            'endpoint' => 'https://svc:same-password@gw.example.com/v1',
            'api_key' => 'same-password',
        ]);

        self::assertSame([], $errors);
    }

    /**
     * The two supported shapes must stay quiet, or the warning becomes noise
     * that editors learn to ignore.
     */
    #[Test]
    public function theSupportedShapesProduceNoWarning(): void
    {
        self::assertSame([], $this->saveCollectingErrors([
            'endpoint' => 'https://svc@gw.example.com/v1',
            'api_key' => 'the-typed-password',
        ]), 'User name in the URL, password in the key field: the documented way.');

        self::assertSame([], $this->saveCollectingErrors([
            'endpoint' => 'https://svc:url-password@gw.example.com/v1',
            'api_key' => '',
        ]), 'Everything in the URL, key field untouched.');

        self::assertSame([], $this->saveCollectingErrors([
            'endpoint' => 'http://localhost:11434',
            'api_key' => '',
        ]), 'A local provider with no credential at all.');
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function saveCollectingErrors(array $values): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => ['NEW1' => array_merge([
            'pid' => 0,
            'ai_provider' => 'ollama',
            'title' => 'probe',
            'model' => '',
        ], $values)]], []);
        $dataHandler->process_datamap();

        return array_values($dataHandler->errorLog);
    }

    /**
     * A stored key is never shown, so a blank submission has to mean "keep it".
     * The ClearApiKey field control writes a marker instead, which is the only
     * way to say "remove it" and the reason repointing a configuration at a
     * different host no longer has to leave the old credential behind.
     */
    #[Test]
    public function theClearMarkerRemovesTheStoredKey(): void
    {
        $uid = $this->save(['endpoint' => 'https://gateway.example.com/v1', 'api_key' => 'sk-real-key']);
        self::assertNotSame('', $this->row($uid)['api_key']);

        $this->update($uid, ['api_key' => EncryptApiKey::CLEAR_MARKER]);

        self::assertSame('', $this->row($uid)['api_key'], 'The stored credential survived the marker.');
    }

    /**
     * The marker must never be stored, encrypted or otherwise.
     */
    #[Test]
    public function theMarkerItselfIsNeverStored(): void
    {
        $uid = $this->save(['endpoint' => 'https://gateway.example.com/v1', 'api_key' => 'sk-real-key']);

        $this->update($uid, ['api_key' => EncryptApiKey::CLEAR_MARKER]);

        $stored = $this->row($uid)['api_key'];
        self::assertStringNotContainsString('aim_clear', $stored);
        self::assertSame('', $stored);
    }

    /**
     * The marker sits in api_key, so it must not be mistaken for an explicitly
     * typed credential and win over one in the endpoint URL.
     */
    #[Test]
    public function theMarkerDoesNotBeatACredentialInTheEndpointUrl(): void
    {
        $uid = $this->save(['endpoint' => 'http://localhost:11434', 'api_key' => 'sk-old']);

        $this->update($uid, [
            'endpoint' => 'https://svc:from-the-url@gw.example.com/v1',
            'api_key' => EncryptApiKey::CLEAR_MARKER,
        ]);

        $row = $this->row($uid);
        self::assertSame('https://svc@gw.example.com/v1', $row['endpoint']);
        self::assertSame('from-the-url', $this->get(ApiKeyEncryption::class)->decrypt($row['api_key']));
    }

    /**
     * Without the marker, a blank submission still keeps the stored key. This
     * is the rule the marker exists to make an exception to, so it has to stay
     * pinned right next to it.
     */
    #[Test]
    public function aBlankSubmissionWithoutTheMarkerStillKeepsTheKey(): void
    {
        $uid = $this->save(['endpoint' => 'https://gateway.example.com/v1', 'api_key' => 'sk-real-key']);
        $stored = $this->row($uid)['api_key'];

        $this->update($uid, ['api_key' => '']);

        self::assertSame($stored, $this->row($uid)['api_key']);
    }

    /**
     * Repointing a configuration at another host while a credential stays
     * stored sends the old credential to the new host. Nothing here can tell
     * whether that was meant, so it reports rather than decides.
     */
    #[Test]
    public function changingTheEndpointWhileKeepingTheKeyIsReported(): void
    {
        $uid = $this->save(['endpoint' => '', 'api_key' => 'sk-openai-production']);

        $errors = $this->updateCollectingErrors($uid, ['endpoint' => 'http://localhost:11434', 'api_key' => '']);

        self::assertCount(1, $errors, 'The editor was not told the old key stays in place.');
        self::assertStringContainsString('sent to the new host', $errors[0]);
        self::assertStringNotContainsString('sk-openai-production', $errors[0]);
    }

    #[Test]
    public function replacingTheKeyWhileChangingTheEndpointIsSilent(): void
    {
        $uid = $this->save(['endpoint' => '', 'api_key' => 'sk-openai-production']);

        $errors = $this->updateCollectingErrors($uid, [
            'endpoint' => 'http://localhost:11434',
            'api_key' => 'sk-the-new-one',
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function clearingTheKeyWhileChangingTheEndpointIsSilent(): void
    {
        $uid = $this->save(['endpoint' => '', 'api_key' => 'sk-openai-production']);

        $errors = $this->updateCollectingErrors($uid, [
            'endpoint' => 'http://localhost:11434',
            'api_key' => EncryptApiKey::CLEAR_MARKER,
        ]);

        self::assertSame([], $errors);
        self::assertSame('', $this->row($uid)['api_key']);
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function updateCollectingErrors(int $uid, array $values): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$uid => $values]], []);
        $dataHandler->process_datamap();

        return array_values($dataHandler->errorLog);
    }

    private function insertLegacyRow(string $apiKey): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'ollama',
            'title' => 'legacy',
            'model' => '',
            'endpoint' => '',
            'api_key' => $apiKey,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(int $uid, array $values): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$uid => $values]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, implode('; ', $dataHandler->errorLog));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(array $values, bool $expectWarning = false): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => ['NEW1' => array_merge([
            'pid' => 0,
            'ai_provider' => 'ollama',
            'title' => 'probe',
            'model' => '',
        ], $values)]], []);
        $dataHandler->process_datamap();
        if ($expectWarning) {
            self::assertCount(1, $dataHandler->errorLog, 'Expected the save to report something.');
        } else {
            self::assertSame([], $dataHandler->errorLog, implode('; ', $dataHandler->errorLog));
        }

        return (int)$dataHandler->substNEWwithIDs['NEW1'];
    }

    /**
     * @return array{endpoint: string, api_key: string}
     */
    private function row(int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['endpoint', 'api_key'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();
        self::assertNotFalse($row);

        return ['endpoint' => (string)$row['endpoint'], 'api_key' => (string)$row['api_key']];
    }

    /**
     * A save that says nothing about the endpoint must not clear api_key.
     * The heal path exists to move a pre-migration URL out of api_key once the
     * endpoint field carries it, and a columnsOnly edit of an unrelated field
     * submits api_key (blank, because the form never pre-fills it) without the
     * endpoint. Clearing it there loses the configuration's only endpoint.
     */
    #[Test]
    public function aSaveWithoutTheEndpointFieldKeepsAPreMigrationUrl(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'ollama',
            'title' => 'legacy',
            'model' => '',
            'api_key' => 'http://localhost:11434',
            'endpoint' => '',
        ]);
        $uid = (int)$connection->lastInsertId();

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$uid => ['title' => 'renamed', 'api_key' => '']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, implode('; ', $dataHandler->errorLog));

        $row = $this->row($uid);
        self::assertSame('http://localhost:11434', $row['api_key'], 'The only copy of the endpoint was dropped.');
        self::assertSame('', $row['endpoint']);
    }
}
