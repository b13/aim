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
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The footprint as the request log renders it: the stats the poll script
 * swaps in, and the detail row for an estimate, a missing one and a row
 * logged before the feature existed.
 */
final class RequestLogFootprintTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'pid' => 0,
            'username' => 'admin',
            'admin' => 1,
        ]);
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function pollReturnsFormattedFootprintAndCoverage(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'openai', 'co2_g_min' => 0.0016, 'co2_g_max' => 0.0020, 'energy_wh_min' => 0.004, 'energy_wh_max' => 0.005, 'impact_method' => 'model_estimate']);
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'ollama']);

        $body = json_decode((string)$this->get(RequestLogController::class)->pollAction($this->request('/ajax/aim/request-log/poll'))->getBody(), true);

        self::assertSame('1.6–2.0 mg CO₂e', $body['statistics']['footprint_display']);
        self::assertSame('1 of 2 requests estimated', $body['statistics']['footprint_coverage']);
        self::assertSame('', $body['statistics']['footprint_routing']);
    }

    #[Test]
    public function pollSaysWhatSmartRoutingAvoided(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log')->insert('tx_aim_request_log', [
            'provider_identifier' => 'openai', 'co2_g_min' => 0.002, 'co2_g_max' => 0.003, 'co2_g_avoided_min' => 0.097, 'co2_g_avoided_max' => 0.198,
        ]);

        $body = json_decode((string)$this->get(RequestLogController::class)->pollAction($this->request('/ajax/aim/request-log/poll'))->getBody(), true);

        self::assertSame('Avoided by smart routing: ≈ 0.10–0.20 g CO₂e · routed requests with an estimate: 1', $body['statistics']['footprint_routing']);
    }

    #[Test]
    public function pollSaysNoDataInsteadOfZero(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log')
            ->insert('tx_aim_request_log', ['provider_identifier' => 'ollama']);

        $body = json_decode((string)$this->get(RequestLogController::class)->pollAction($this->request('/ajax/aim/request-log/poll'))->getBody(), true);

        self::assertSame('No data', $body['statistics']['footprint_display']);
        self::assertSame('0 of 1 requests estimated', $body['statistics']['footprint_coverage']);
    }

    #[Test]
    public function pollRowsCarryTheFootprintCellOrTheReasonForNone(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $connection->insert('tx_aim_request_log', ['crdate' => 2, 'provider_identifier' => 'openai', 'co2_g_min' => 0.0016, 'co2_g_max' => 0.0020, 'energy_wh_min' => 0.004, 'energy_wh_max' => 0.005, 'impact_method' => 'model_estimate']);
        $connection->insert('tx_aim_request_log', ['crdate' => 1, 'provider_identifier' => 'ollama', 'impact_details' => json_encode(['unavailable' => 'no_data_source'])]);

        $rows = json_decode((string)$this->get(RequestLogController::class)->pollAction($this->request('/ajax/aim/request-log/poll'))->getBody(), true)['rows'];

        self::assertSame('1.6–2.0 mg CO₂e', $rows[0]['footprint']);
        self::assertSame('4.0–5.0 mWh', $rows[0]['footprint_title']);
        self::assertSame('–', $rows[1]['footprint']);
        self::assertSame('No data: No model data for this provider and no power draw configured.', $rows[1]['footprint_title']);
    }

    #[Test]
    public function listShowsTheFootprintNextToTheCost(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $connection->insert('tx_aim_request_log', ['crdate' => 2, 'provider_identifier' => 'openai', 'co2_g_min' => 0.0016, 'co2_g_max' => 0.0020, 'energy_wh_min' => 0.004, 'energy_wh_max' => 0.005, 'impact_method' => 'model_estimate']);
        $connection->insert('tx_aim_request_log', ['crdate' => 1, 'provider_identifier' => 'ollama', 'impact_details' => json_encode(['unavailable' => 'no_data_source'])]);

        $html = (string)$this->get(RequestLogController::class)->logAction($this->request('/module/aim/request-log'))->getBody();

        self::assertStringContainsString('CO₂e (est.)', $html);
        self::assertStringContainsString('title="4.0–5.0 mWh">1.6–2.0 mg CO₂e</td>', $html);
        self::assertStringContainsString('title="No data: No model data for this provider and no power draw configured.">–</td>', $html);
    }

    #[Test]
    public function pollRowsCanBeSortedByFootprint(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'none']);
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'small', 'co2_g_min' => 0.001, 'co2_g_max' => 0.002]);
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'large', 'co2_g_min' => 0.1, 'co2_g_max' => 0.2]);

        // Rows without an estimate last in both directions, on every DBMS
        foreach (['desc' => ['large', 'small', 'none'], 'asc' => ['small', 'large', 'none']] as $direction => $expected) {
            $request = $this->request('/ajax/aim/request-log/poll')->withQueryParams(['orderField' => 'co2_g_max', 'orderDirection' => $direction]);
            $rows = json_decode((string)$this->get(RequestLogController::class)->pollAction($request)->getBody(), true)['rows'];
            self::assertSame($expected, array_column($rows, 'provider_identifier'), $direction);
        }
    }

    #[Test]
    public function detailShowsTheEstimateWithItsInputs(): void
    {
        $html = $this->renderDetail([
            'provider_identifier' => 'openai',
            'co2_g_min' => 0.0016380514,
            'co2_g_max' => 0.0019532425,
            'energy_wh_min' => 0.0042613,
            'energy_wh_max' => 0.0050812,
            'impact_method' => 'model_estimate',
            'impact_details' => json_encode([
                'method' => 'model_estimate',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'activeParameters' => ['min' => 8, 'max' => 28],
                'gpus' => ['min' => 1, 'max' => 1],
                'pue' => ['min' => 1.2, 'max' => 1.2],
                'outputTokens' => 60,
                'generationSeconds' => ['min' => 1.3, 'max' => 1.3],
                'embodiedCo2Grams' => ['min' => 0.00021, 'max' => 0.00021],
                'gridIntensity' => 384.4,
                'gridSource' => 'USA',
                'warnings' => ['model-arch-not-released'],
                'dataVersion' => 'd586c3566da37ecd2c35878d77ae6e7de1a0719c',
            ]),
        ]);

        self::assertStringContainsString('1.6–2.0 mg CO₂e', $html);
        self::assertStringContainsString('4.3–5.1 mWh', $html);
        self::assertStringContainsString('EcoLogits model', $html);
        self::assertStringContainsString('8–28 B', $html);
        self::assertStringContainsString('USA, EcoLogits electricity mix', $html);
        self::assertStringContainsString('Hardware manufacturing share', $html);
        self::assertStringContainsString('Model architecture not published', $html);
        self::assertStringContainsString('EcoLogits d586c35', $html);
        self::assertStringNotContainsString('gpt-4o-mini (openai)', $html);
        self::assertStringNotContainsString('not included above', $html);
    }

    #[Test]
    public function detailShowsWhyAnEstimateIsMissing(): void
    {
        $html = $this->renderDetail([
            'provider_identifier' => 'ollama',
            'impact_details' => json_encode(['unavailable' => 'no_data_source']),
        ]);

        self::assertStringContainsString('No data', $html);
        self::assertStringContainsString('No model data for this provider and no power draw configured.', $html);
        self::assertStringNotContainsString('CO₂e</strong>', $html);
    }

    #[Test]
    public function detailOfARowLoggedBeforeTheFeatureSaysSo(): void
    {
        $html = $this->renderDetail(['provider_identifier' => 'openai']);

        self::assertStringContainsString('Logged before footprint estimation was available.', $html);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderDetail(array $row): string
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $connection->insert('tx_aim_request_log', $row + ['request_type' => 'VisionRequest', 'success' => 1]);
        $uid = (int)$connection->lastInsertId();

        $request = $this->request('/module/aim/request-log/show-contextual')->withQueryParams(['uid' => $uid]);
        return (string)$this->get(RequestLogController::class)->showContextualAction($request)->getBody();
    }

    private function request(string $path): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3' . $path,
            'REQUEST_METHOD' => 'GET',
        ];
        $request = new ServerRequest('https://typo3-testing.local/typo3' . $path, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('route', new Route($path, ['packageName' => 'b13/aim']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
