<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Widgets;

use B13\Aim\Widgets\FootprintWidget;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The AI Footprint dashboard widget as the registered service renders it.
 */
final class FootprintWidgetTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'dashboard',
    ];

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
    public function rendersTotalsCoverageRoutingAndProviders(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $connection->insert('tx_aim_request_log', [
            'provider_identifier' => 'openai',
            'co2_g_min' => 0.002,
            'co2_g_max' => 0.003,
            'energy_wh_min' => 0.005,
            'energy_wh_max' => 0.008,
            'co2_g_avoided_min' => 0.097,
            'co2_g_avoided_max' => 0.198,
            'impact_method' => 'model_estimate',
        ]);
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'ollama']);

        $html = $this->render();

        self::assertStringContainsString('2.0–3.0 mg CO₂e', $html);
        self::assertStringContainsString('1 of 2 requests estimated', $html);
        self::assertStringContainsString('Avoided by smart routing: ≈ 0.10–0.20 g CO₂e · routed requests with an estimate: 1', $html);
        self::assertMatchesRegularExpression('#<td>openai</td>.*?<td>1 / 1</td>#s', $html);
        self::assertMatchesRegularExpression('#<td>ollama</td>.*?No data.*?<td>0 / 1</td>#s', $html);
    }

    #[Test]
    public function saysWhatSmartRoutingAddedWithoutTheAvoidedHighlight(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log')->insert('tx_aim_request_log', [
            'provider_identifier' => 'openai',
            'co2_g_min' => 0.002,
            'co2_g_max' => 0.003,
            'co2_g_avoided_min' => -0.2,
            'co2_g_avoided_max' => -0.1,
        ]);

        $html = $this->render();

        self::assertStringContainsString('Added by smart routing: ≈ 0.10–0.20 g CO₂e · routed requests with an estimate: 1', $html);
        self::assertStringNotContainsString('text-success', $html);
    }

    #[Test]
    public function saysNothingWasEstimatedInsteadOfZero(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log')
            ->insert('tx_aim_request_log', ['provider_identifier' => 'ollama']);

        $html = $this->render();

        self::assertStringContainsString('No request has been estimated yet.', $html);
        self::assertStringNotContainsString('CO₂e', $html);
    }

    private function render(): string
    {
        $path = '/module/dashboard';
        $request = new ServerRequest('https://typo3-testing.local/typo3' . $path, 'GET', 'php://input', [], [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3' . $path,
            'REQUEST_METHOD' => 'GET',
        ]);
        $request = $request->withAttribute('route', new Route($path, ['packageName' => 'typo3/cms-dashboard']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $widget = $this->get('dashboard.widget.aim_footprint');
        self::assertInstanceOf(FootprintWidget::class, $widget);
        $widget->setRequest($request);
        return $widget->renderWidgetContent();
    }
}
