<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Tca;

use B13\Aim\Backend\FormDataProvider\HideApiKey;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Provider\LiveModelDiscovery;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Registry\DisabledModelRegistry;
use B13\Aim\Tca\ItemsProcFunc\AiProvidersItemsProcFunc;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Model discovery has to authenticate with the decrypted credential.
 *
 * The row is put through the real HideApiKey provider first, because that is
 * the state FormEngine hands to the itemsProcFunc. FormDataProviderOrderTest
 * pins the other half of that contract, namely that HideApiKey runs first at
 * all: if it does not, api_key still holds the ciphertext here and discovery
 * ships it to the endpoint.
 */
final class DiscoveryCredentialTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';
    private const SECRET = 'sk-plaintext-secret';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    /** @var array<string, mixed> */
    private array $capturedOptions = [];

    private string $capturedUri = '';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);

        // The itemsProcFunc resolves labels through the current backend user.
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);

        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'selfhosted',
            name: 'Self Hosted',
            description: '',
            iconIdentifier: '',
            // Empty catalog is what triggers live discovery.
            supportedModels: [],
            capabilities: [TextGenerationCapableInterface::class],
            serviceName: 'test.provider',
            container: $this->createMock(ContainerInterface::class),
        ));
    }

    #[Test]
    public function theStoredCredentialIsSentDecrypted(): void
    {
        $row = $this->storeConfiguration();

        $items = $this->resolveModelItems($row);

        self::assertSame(
            ['Authorization' => 'Bearer ' . self::SECRET],
            $this->capturedOptions['headers'] ?? [],
            'Discovery must authenticate with the decrypted credential, not the stored ciphertext.',
        );
        self::assertContains('llama3.2:latest', array_column($items, 'value'));
    }

    /**
     * The ciphertext is what ApiKeyEncryption exists to keep inside the
     * installation, so it must never be handed to a third-party host, neither
     * in a header nor merged into the request URL.
     */
    #[Test]
    public function theStoredCiphertextNeverLeavesTheInstallation(): void
    {
        $row = $this->storeConfiguration();

        $this->resolveModelItems($row);

        $sent = json_encode([$this->capturedUri, $this->capturedOptions], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(ApiKeyEncryption::PREFIX_V1, $sent);
        self::assertStringNotContainsString(ApiKeyEncryption::PREFIX_V2, $sent);
        self::assertStringNotContainsString('aim:enc:', $sent);
    }

    /**
     * @return array<string, mixed> the row as FormEngine hands it on, i.e. after HideApiKey
     */
    private function storeConfiguration(): array
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'selfhosted',
            'title' => 'local gateway',
            'endpoint' => 'http://localhost:11434',
            'api_key' => $this->get(ApiKeyEncryption::class)->encrypt(self::SECRET),
            'model' => '',
        ]);
        $uid = (int)$connection->lastInsertId();

        $row = $connection->select(['*'], self::TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);
        self::assertStringStartsWith('aim:enc:', (string)$row['api_key'], 'The column must hold ciphertext.');

        // What FormEngine actually hands the itemsProcFunc.
        $result = $this->get(HideApiKey::class)->addData([
            'tableName' => self::TABLE,
            'databaseRow' => $row,
            'processedTca' => ['columns' => ['api_key' => ['config' => []]]],
        ]);

        return $result['databaseRow'];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function resolveModelItems(array $row): array
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn('{"data":[{"id":"llama3.2:latest"}]}');
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($body);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method = 'GET', array $options = []) use ($response): ResponseInterface {
                $this->capturedUri = $uri;
                $this->capturedOptions = $options;

                return $response;
            }
        );

        $itemsProcFunc = new AiProvidersItemsProcFunc(
            $this->get(AiProviderRegistry::class),
            $this->get(DisabledModelRegistry::class),
            $this->get(LanguageServiceFactory::class),
            new LiveModelDiscovery($requestFactory, $this->get(CacheManager::class)),
            $this->get(ProviderConfigurationRepository::class),
        );

        $fieldDefinition = ['items' => [], 'row' => $row];
        $itemsProcFunc->getAiProviderModels($fieldDefinition);

        return $fieldDefinition['items'];
    }
}
