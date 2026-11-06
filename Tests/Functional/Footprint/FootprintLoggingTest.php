<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Footprint;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Model\ProviderConfigurationFactory;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Footprint\Display;
use B13\Aim\Footprint\EcoLogitsData;
use B13\Aim\Footprint\Estimator;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\RequestLoggingMiddleware;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FootprintLoggingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function estimateIsPersistedWithItsInputs(): void
    {
        $this->log(
            ['ai_provider' => 'openai', 'model' => 'gpt-4o-mini'],
            new TextResponse('A red bicycle.', new AiUsageStatistics(completionTokens: 60, modelUsed: 'gpt-4o-mini')),
        );

        $row = $this->fetchSingleLogRow();
        self::assertSame('model_estimate', $row['impact_method']);
        self::assertGreaterThan(0.0, (float)$row['co2_g_min']);
        self::assertGreaterThanOrEqual((float)$row['co2_g_min'], (float)$row['co2_g_max']);
        self::assertGreaterThan(0.0, (float)$row['energy_wh_min']);
        $details = json_decode($row['impact_details'], true);
        self::assertSame('gpt-4o-mini', $details['model']);
        self::assertSame(60, $details['outputTokens']);
        self::assertSame('USA', $details['gridSource']);
    }

    #[Test]
    public function theDetailViewReadsWhatAModelEstimateLogged(): void
    {
        $configuration = ProviderConfigurationFactory::markedAsDowngrade(
            $this->configuration(['ai_provider' => 'openai', 'model' => 'gpt-4o-mini']),
            'gpt-4o',
            'smart routing: simple prompt',
            $this->configuration(['ai_provider' => 'openai', 'model' => 'gpt-4o']),
        );
        $this->middleware()->process(
            new TextGenerationRequest(configuration: $configuration, prompt: 'Hi'),
            $this->createMock(AiProviderInterface::class),
            $configuration,
            new AiMiddlewareHandler(static fn() => new TextResponse('Hello.', new AiUsageStatistics(completionTokens: 60, modelUsed: 'gpt-4o-mini-2024-07-18'))),
        );

        $footprint = $this->display()->entry($this->fetchSingleLogRow());

        self::assertTrue($footprint['available']);
        self::assertSame(
            ['activeParameters', 'gpus', 'pue', 'outputTokens', 'generationSeconds', 'embodiedCo2Grams', 'requestedModel', 'routingChange', 'gridIntensity'],
            array_keys($footprint['facts']),
        );
        self::assertSame('8–28 B', $footprint['facts']['activeParameters']);
        self::assertSame('60', $footprint['facts']['outputTokens']);
        self::assertStringEndsWith('CO₂e avoided', $footprint['facts']['routingChange']);
        self::assertStringContainsString('USA', $footprint['facts']['gridIntensity']);
        self::assertContains('model-arch-not-released', $footprint['warnings']);
        self::assertSame('d586c35', $footprint['dataVersion']);
    }

    #[Test]
    public function theDetailViewReadsWhatAConfiguredPowerDrawLogged(): void
    {
        $this->log(
            ['ai_provider' => 'ollama', 'model' => 'gemma3:4b', 'power_draw_watts' => '36.00'],
            new TextResponse('A red bicycle.', new AiUsageStatistics(
                completionTokens: 12,
                rawUsage: ['total_duration' => 3_000_000_000, 'load_duration' => 1_000_000_000],
            )),
        );

        $footprint = $this->display()->entry($this->fetchSingleLogRow());

        self::assertSame(['powerDrawWatts', 'inferenceSeconds', 'durationSource', 'gridIntensity'], array_keys($footprint['facts']));
        self::assertSame('36.0 W', $footprint['facts']['powerDrawWatts']);
        self::assertSame('2.00 s', $footprint['facts']['inferenceSeconds']);
        self::assertNotSame('', $footprint['facts']['durationSource']);
    }

    #[Test]
    public function smartRoutedRequestStoresWhatRoutingAvoided(): void
    {
        $configuration = ProviderConfigurationFactory::markedAsDowngrade(
            $this->configuration(['ai_provider' => 'openai', 'model' => 'gpt-4o-mini']),
            'gpt-4o',
            'smart routing: simple prompt',
            $this->configuration(['ai_provider' => 'openai', 'model' => 'gpt-4o']),
        );
        $this->middleware()->process(
            new TextGenerationRequest(configuration: $configuration, prompt: 'Hi'),
            $this->createMock(AiProviderInterface::class),
            $configuration,
            new AiMiddlewareHandler(static fn() => new TextResponse('Hello.', new AiUsageStatistics(completionTokens: 200, modelUsed: 'gpt-4o-mini-2024-07-18'))),
        );

        $row = $this->fetchSingleLogRow();
        self::assertSame('gpt-4o', $row['model_requested']);
        $requested = json_decode($row['impact_details'], true)['requestedModel'];
        self::assertSame('gpt-4o', $requested['model']);
        self::assertEqualsWithDelta(0.1127250674, $requested['co2Grams']['min'], 1e-9);
        self::assertEqualsWithDelta((float)$row['co2_g_avoided_min'], $requested['avoidedCo2Grams']['min'], 1e-9);
        self::assertGreaterThan(0.0, (float)$row['co2_g_avoided_min']);
        self::assertGreaterThan((float)$row['co2_g_avoided_min'], (float)$row['co2_g_avoided_max']);
    }

    #[Test]
    public function requestWithoutSmartRoutingHasNoRequestedModelEstimate(): void
    {
        $this->log(
            ['ai_provider' => 'openai', 'model' => 'gpt-4o-mini'],
            new TextResponse('Hello.', new AiUsageStatistics(completionTokens: 60, modelUsed: 'gpt-4o-mini')),
        );

        self::assertNull($this->fetchSingleLogRow()['co2_g_avoided_min']);
    }

    #[Test]
    public function statisticsSumWhatSmartRoutingAvoided(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'openai', 'co2_g_min' => 0.002, 'co2_g_max' => 0.003, 'co2_g_avoided_min' => 0.097, 'co2_g_avoided_max' => 0.198]);
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'openai', 'co2_g_min' => 0.002, 'co2_g_max' => 0.003, 'co2_g_avoided_min' => -0.001, 'co2_g_avoided_max' => 0.196]);
        $connection->insert('tx_aim_request_log', ['provider_identifier' => 'openai', 'co2_g_min' => 0.002, 'co2_g_max' => 0.003]);

        $statistics = $this->get(RequestLogRepository::class)->getStatistics();

        self::assertSame(2, $statistics['footprint_routing_estimated_requests']);
        self::assertEqualsWithDelta(0.096, $statistics['footprint_avoided_min'], 1e-9);
        self::assertEqualsWithDelta(0.394, $statistics['footprint_avoided_max'], 1e-9);
    }

    #[Test]
    public function missingEstimateIsPersistedAsNullWithItsReason(): void
    {
        $this->log(
            ['ai_provider' => 'ollama', 'model' => 'gemma3:4b'],
            new TextResponse('A red bicycle.', new AiUsageStatistics(completionTokens: 12)),
        );

        $row = $this->fetchSingleLogRow();
        self::assertNull($row['co2_g_min']);
        self::assertNull($row['co2_g_max']);
        self::assertNull($row['energy_wh_min']);
        self::assertNull($row['energy_wh_max']);
        self::assertSame('', $row['impact_method']);
        self::assertSame(['unavailable' => 'no_data_source'], json_decode($row['impact_details'], true));
    }

    #[Test]
    public function reducedPrivacyStillEstimatesFromProviderTimings(): void
    {
        $this->log(
            ['ai_provider' => 'ollama', 'model' => 'gemma3:4b', 'privacy_level' => 'reduced', 'power_draw_watts' => '36.00'],
            new TextResponse('A red bicycle.', new AiUsageStatistics(
                completionTokens: 12,
                rawUsage: ['total_duration' => 3_000_000_000, 'load_duration' => 1_000_000_000],
            )),
        );

        $row = $this->fetchSingleLogRow();
        self::assertSame('', $row['raw_usage']);
        self::assertSame('duration_calibrated', $row['impact_method']);
        self::assertEqualsWithDelta(0.02, (float)$row['energy_wh_min'], 1e-9);
    }

    #[Test]
    public function estimationErrorDoesNotBreakTheResponse(): void
    {
        $estimator = $this->createMock(Estimator::class);
        $estimator->method('estimate')->willThrowException(new \DivisionByZeroError('Division by zero'));
        $configuration = $this->configuration(['ai_provider' => 'openai', 'model' => 'gpt-4o-mini']);
        $response = (new RequestLoggingMiddleware($this->get(RequestLogRepository::class), new NullLogger(), $estimator))->process(
            new TextGenerationRequest(configuration: $configuration, prompt: 'Write an alt text.'),
            $this->createMock(AiProviderInterface::class),
            $configuration,
            new AiMiddlewareHandler(static fn() => new TextResponse('A red bicycle.', new AiUsageStatistics(completionTokens: 60))),
        );

        self::assertSame('A red bicycle.', $response->content);
        $row = $this->fetchSingleLogRow();
        self::assertNull($row['co2_g_min']);
        self::assertSame(['unavailable' => 'estimation_failed'], json_decode($row['impact_details'], true));
    }

    #[Test]
    public function failedRequestIsLoggedWithoutEstimate(): void
    {
        $this->log(
            ['ai_provider' => 'openai', 'model' => 'gpt-4o-mini'],
            new TextResponse('', new AiUsageStatistics(completionTokens: 60), errors: ['Rate limited']),
        );

        $row = $this->fetchSingleLogRow();
        self::assertNull($row['co2_g_min']);
        self::assertSame(['unavailable' => 'request_failed'], json_decode($row['impact_details'], true));
    }

    #[Test]
    public function embeddingRequestIsLoggedWithoutEstimate(): void
    {
        $configuration = $this->configuration(['ai_provider' => 'openai', 'model' => 'text-embedding-3-small']);
        $this->middleware()->process(
            new EmbeddingRequest(configuration: $configuration, input: ['Hello']),
            $this->createMock(AiProviderInterface::class),
            $configuration,
            new AiMiddlewareHandler(static fn() => new TextResponse('[0.1]', new AiUsageStatistics(promptTokens: 2))),
        );

        $row = $this->fetchSingleLogRow();
        self::assertNull($row['co2_g_min']);
        self::assertSame(['unavailable' => 'unsupported_request_type'], json_decode($row['impact_details'], true));
    }

    #[Test]
    public function statisticsSumOnlyEstimatedRequestsAndReportCoverage(): void
    {
        $this->insertLogRows();
        $statistics = $this->get(RequestLogRepository::class)->getStatistics();

        self::assertSame(4, $statistics['total_requests']);
        self::assertSame(2, $statistics['footprint_estimated_requests']);
        self::assertEqualsWithDelta(0.3, $statistics['footprint_co2_min'], 1e-9);
        self::assertEqualsWithDelta(0.6, $statistics['footprint_co2_max'], 1e-9);
        self::assertEqualsWithDelta(3.0, $statistics['footprint_energy_min'], 1e-9);
        self::assertEqualsWithDelta(6.0, $statistics['footprint_energy_max'], 1e-9);
    }

    #[Test]
    public function statisticsWithoutAnyEstimateReportNoCoverage(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log')
            ->insert('tx_aim_request_log', ['configuration_uid' => 1, 'provider_identifier' => 'ollama']);
        $statistics = $this->get(RequestLogRepository::class)->getStatistics();

        self::assertSame(1, $statistics['total_requests']);
        self::assertSame(0, $statistics['footprint_estimated_requests']);
    }

    #[Test]
    public function footprintIsAggregatedPerConfiguration(): void
    {
        $this->insertLogRows();
        $perConfiguration = $this->get(RequestLogRepository::class)->getFootprintPerConfiguration();

        self::assertSame(3, $perConfiguration[1]['requests']);
        self::assertSame(2, $perConfiguration[1]['estimated_requests']);
        self::assertEqualsWithDelta(0.3, $perConfiguration[1]['co2_min'], 1e-9);
        self::assertSame(1, $perConfiguration[2]['requests']);
        self::assertSame(0, $perConfiguration[2]['estimated_requests']);
        self::assertSame(0.0, $perConfiguration[2]['co2_max']);
    }

    #[Test]
    public function footprintIsAggregatedPerProvider(): void
    {
        $this->insertLogRows();
        $perProvider = $this->get(RequestLogRepository::class)->getFootprintByProvider();

        $byIdentifier = array_column($perProvider, null, 'provider_identifier');
        self::assertSame(3, $byIdentifier['openai']['requests']);
        self::assertSame(2, $byIdentifier['openai']['estimated_requests']);
        self::assertEqualsWithDelta(0.6, $byIdentifier['openai']['co2_max'], 1e-9);
        self::assertSame(0, $byIdentifier['ollama']['estimated_requests']);
    }

    private function insertLogRows(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log');
        $estimated = ['configuration_uid' => 1, 'provider_identifier' => 'openai', 'impact_method' => 'model_estimate'];
        $connection->insert('tx_aim_request_log', $estimated + ['co2_g_min' => 0.1, 'co2_g_max' => 0.2, 'energy_wh_min' => 1.0, 'energy_wh_max' => 2.0]);
        $connection->insert('tx_aim_request_log', $estimated + ['co2_g_min' => 0.2, 'co2_g_max' => 0.4, 'energy_wh_min' => 2.0, 'energy_wh_max' => 4.0]);
        $connection->insert('tx_aim_request_log', ['configuration_uid' => 1, 'provider_identifier' => 'openai']);
        $connection->insert('tx_aim_request_log', ['configuration_uid' => 2, 'provider_identifier' => 'ollama']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function log(array $row, TextResponse $response): void
    {
        $configuration = $this->configuration($row);
        $this->middleware()->process(
            new TextGenerationRequest(configuration: $configuration, prompt: 'Write an alt text.'),
            $this->createMock(AiProviderInterface::class),
            $configuration,
            new AiMiddlewareHandler(static fn() => $response),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function configuration(array $row): ProviderConfiguration
    {
        return new ProviderConfiguration(array_merge(['uid' => 1, 'title' => 'Test', 'privacy_level' => 'standard'], $row));
    }

    private function display(): Display
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        return new Display();
    }

    private function middleware(): RequestLoggingMiddleware
    {
        return new RequestLoggingMiddleware(
            $this->get(RequestLogRepository::class),
            new NullLogger(),
            new Estimator(new EcoLogitsData()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSingleLogRow(): array
    {
        $rows = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->select(['*'], 'tx_aim_request_log')
            ->fetchAllAssociative();
        self::assertCount(1, $rows);
        return $rows[0];
    }
}
