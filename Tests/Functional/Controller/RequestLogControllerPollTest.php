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

use B13\Aim\Controller\RequestLogController;
use B13\Aim\Domain\Repository\RequestLogRepository;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression coverage for pollAction()'s JSON row shape: it used to omit
 * user_id/username and grade_error entirely, even though the server-rendered
 * table (RequestLog/Row.html) has User and Grade columns that depend on
 * them. request-log-poll.js built rows with fewer <td>s than the table
 * header has, so every auto-refresh broke the table's column alignment.
 */
final class RequestLogControllerPollTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function pollActionIncludesEveryFieldTheUserAndGradeColumnsNeed(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'pid' => 0,
            'username' => 'admin',
            'admin' => 1,
        ]);
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 42,
            'pid' => 0,
            'username' => 'jane',
        ]);

        $logRepo = $this->get(RequestLogRepository::class);
        $logRepo->log([
            'request_type' => 'TextGenerationRequest',
            'provider_identifier' => 'test',
            'extension_key' => 'aim',
            'user_id' => 42,
            'grade_status' => 'failed',
            'grade_error' => 'judge provider unavailable',
        ]);

        $body = json_decode((string)$this->poll()->getBody(), true);

        self::assertCount(1, $body['rows']);
        $row = $body['rows'][0];
        self::assertSame(42, $row['user_id']);
        self::assertSame('jane', $row['username']);
        self::assertSame('failed', $row['grade_status']);
        self::assertSame('judge provider unavailable', $row['grade_error']);
        // resolveExtensionIcons() is pre-existing, shared logic already
        // exercised by logAction()'s own tests, but pollAction()'s own JSON
        // shape never asserted this field is actually populated - 'aim'
        // itself ships Resources/Public/Icons/Extension.svg, so this is a
        // real, always-available icon rather than a fixture-only one.
        self::assertStringEndsWith('Extension.svg', $row['extension_icon']);
    }

    #[Test]
    public function pollActionResolvesAUsernamelessUserIdToAnEmptyUsernameInsteadOfDroppingTheRow(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'pid' => 0,
            'username' => 'admin',
            'admin' => 1,
        ]);
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $logRepo = $this->get(RequestLogRepository::class);
        $logRepo->log([
            'request_type' => 'TextGenerationRequest',
            'provider_identifier' => 'test',
            'user_id' => 999,
        ]);

        $body = json_decode((string)$this->poll()->getBody(), true);

        self::assertCount(1, $body['rows']);
        self::assertSame(999, $body['rows'][0]['user_id']);
        self::assertSame('', $body['rows'][0]['username']);
    }

    /**
     * Regression test: this action is a raw AJAX route
     * (Configuration/Backend/AjaxRoutes.php), never validated by
     * BackendModuleValidator the way the aim_request_log MODULE route is -
     * unlike the module (`'access' => 'admin'` in
     * Configuration/Backend/Modules.php), nothing previously stopped a
     * non-admin backend user from calling this endpoint directly and
     * getting back the full request log (prompts, responses, costs, other
     * users' activity), module access notwithstanding.
     */
    #[Test]
    public function pollActionDeniesANonAdminBackendUserRegardlessOfModuleAccess(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 2,
            'pid' => 0,
            'username' => 'non-admin',
            'admin' => 0,
        ]);
        $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $logRepo = $this->get(RequestLogRepository::class);
        $logRepo->log(['request_type' => 'TextGenerationRequest', 'provider_identifier' => 'test']);

        $response = $this->poll();

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    private function poll(): ResponseInterface
    {
        $controller = $this->get(RequestLogController::class);
        $url = 'https://typo3-testing.local/typo3/ajax/aim/request-log/poll';
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3/ajax/aim/request-log/poll',
            'REQUEST_METHOD' => 'GET',
        ];
        $request = new ServerRequest($url, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('route', new Route('/ajax/aim/request-log/poll', ['packageName' => 'b13/aim']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $controller->pollAction($request);
    }
}
