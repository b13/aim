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
use B13\Aim\Registry\DisabledModelRegistry;
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
        $beUsers->insert('be_users', ['uid' => 2, 'username' => 'non-admin', 'admin' => 0]);

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

    /**
     * Regression test: availableProvidersAction/toggleModelAction/verifyProviderAction
     * are raw AJAX routes (Configuration/Backend/AjaxRoutes.php), never
     * validated by BackendModuleValidator - unlike this action's own
     * module (aim_providers, 'access' => 'admin' in
     * Configuration/Backend/Modules.php), which gates overviewAction(),
     * nothing previously stopped any authenticated non-admin backend user
     * from calling any of these three directly: reading the full
     * provider/model catalog, globally toggling a model's enabled state
     * for every user of the instance, or triggering a real, potentially
     * billable outbound API call.
     */
    #[Test]
    public function availableProvidersActionDeniesANonAdminBackendUser(): void
    {
        $this->setUpBackendUser(2);

        $request = $this->buildRequestWithNormalizedParams();
        $response = $this->get(ProviderController::class)->availableProvidersAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Regression test: the deny-path test above only proves a non-admin is
     * rejected, not that the admin isAdmin() gate itself still lets an
     * actual admin through - a check inverted by accident (e.g. `if
     * ($this->getBackendUser()->isAdmin())`) would pass the deny test too.
     */
    #[Test]
    public function availableProvidersActionAllowsAnAdminBackendUser(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $request = $this->buildRequestWithNormalizedParams();
        $response = $this->get(ProviderController::class)->availableProvidersAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-provider="openai"', (string)$response->getBody());
    }

    #[Test]
    public function toggleModelActionDeniesANonAdminBackendUser(): void
    {
        $this->setUpBackendUser(2);

        $request = $this->buildRequestWithNormalizedParams()->withParsedBody(['provider' => 'openai', 'model' => 'gpt-4o']);
        $response = $this->get(ProviderController::class)->toggleModelAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Regression test: see availableProvidersActionAllowsAnAdminBackendUser()'s
     * identical rationale - also proves the toggle actually persists (not
     * just a 200), via DisabledModelRegistry directly.
     */
    #[Test]
    public function toggleModelActionAllowsAnAdminBackendUserAndPersistsTheToggle(): void
    {
        $this->setUpBackendUser(1);

        $request = $this->buildRequestWithNormalizedParams()->withParsedBody(['provider' => 'openai', 'model' => 'gpt-4o']);
        $response = $this->get(ProviderController::class)->toggleModelAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertTrue($body['disabled']);
        self::assertTrue($this->get(DisabledModelRegistry::class)->isDisabled('openai', 'gpt-4o'));
    }

    #[Test]
    public function verifyProviderActionDeniesANonAdminBackendUser(): void
    {
        $this->setUpBackendUser(2);

        $request = $this->buildRequestWithNormalizedParams()->withParsedBody(['uid' => 1]);
        $response = $this->get(ProviderController::class)->verifyProviderAction($request);

        self::assertSame(403, $response->getStatusCode());
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
