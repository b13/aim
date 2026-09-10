<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Domain\Repository;

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Domain\Repository\ProviderConfigurationDemand;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Reading a configuration must not depend on its credential still being
 * decryptable. After a SYS/encryptionKey rotation without aim:rotateApiKeys,
 * every stored ciphertext is unreadable, and a throw from the mapping would
 * take the Providers module, the request log and every dispatch with it, with
 * only a database edit to get out of it.
 */
final class ProviderConfigurationRepositoryTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function aCredentialThatCannotBeDecryptedIsTreatedAsUnset(): void
    {
        $uid = $this->insertWithEncryptedKey('sk-proj-A1b2C3d4E5f6');

        // The rotation, without the command that re-encrypts.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = bin2hex(random_bytes(48));

        $subject = $this->get(ProviderConfigurationRepository::class);

        $configuration = $subject->findByUid($uid);
        self::assertNotNull($configuration);
        self::assertSame('', $configuration->apiKey, 'The unreadable value must not be handed on as a credential.');

        // The listing paths the backend modules use have to survive it too.
        self::assertNotSame([], $subject->findByDemand(new ProviderConfigurationDemand()));
        self::assertSame(1, $subject->countByDemand(new ProviderConfigurationDemand()));
    }

    private function insertWithEncryptedKey(string $plaintext): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'openai',
            'title' => 'rotated away',
            'model' => 'gpt-4o',
            'api_key' => $this->get(ApiKeyEncryption::class)->encrypt($plaintext),
        ]);

        return (int)$connection->lastInsertId();
    }
}
