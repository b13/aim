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

use B13\Aim\Controller\ProviderController;
use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Registry\AiProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the security fix for overviewAction() pushing the DECRYPTED
 * api_key (see ProviderConfigurationRepository::mapSingleRow()) into the
 * Fluid rendering context for no reason. It does this end-to-end through
 * a real render, not just a unit assertion on the row array, since the
 * actual guarantee that matters is "the plaintext key never reaches the
 * response body".
 */
final class ProviderControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private const PLAINTEXT_KEY = 'sk-super-secret-value-should-never-be-visible';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);

        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);

        $configurations = $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration');
        $configurations->insert('tx_aim_configuration', [
            'pid' => 0,
            'ai_provider' => 'openai',
            'title' => 'Test Configuration',
            'api_key' => (new ApiKeyEncryption())->encrypt(self::PLAINTEXT_KEY),
            'model' => 'gpt-4o',
        ]);

        // overviewAction()'s table only renders (rather than a "no
        // providers" empty state) when at least one provider is registered
        // with the AiProviderRegistry singleton. This test never actually
        // dispatches a real request through it (getInstance() is never
        // called on this path), so a dummy service name/container is fine.
        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'openai',
            name: 'OpenAI',
            description: '',
            iconIdentifier: '',
            supportedModels: ['gpt-4o' => 'GPT-4o'],
            capabilities: [],
            serviceName: 'dummy',
            container: $this->get(ContainerInterface::class),
        ));
    }

    #[Test]
    public function overviewActionNeverExposesTheDecryptedApiKey(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $body = (string)$this->overview()->getBody();

        self::assertStringContainsString('Test Configuration', $body);
        self::assertStringNotContainsString(self::PLAINTEXT_KEY, $body);
    }

    private function overview(): ResponseInterface
    {
        $controller = $this->get(ProviderController::class);
        $request = $this->buildRequestWithNormalizedParams();

        return $controller->overviewAction($request);
    }

    private function buildRequestWithNormalizedParams(): ServerRequest
    {
        $url = 'https://typo3-testing.local/typo3/module/admin/aim/providers';
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => parse_url($url, PHP_URL_PATH),
            'REQUEST_METHOD' => 'GET',
        ];
        $request = new ServerRequest($url, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('route', new Route('/module/admin/aim/providers', ['packageName' => 'b13/aim']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
