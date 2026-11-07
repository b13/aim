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

use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What smart routing avoided, through the real pipeline from the routing
 * decision to the logged row.
 */
final class SmartRoutingFootprintTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $provider = new class () implements AiProviderInterface, TextGenerationCapableInterface {
            public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
            {
                return new TextResponse('Hello.', new AiUsageStatistics(completionTokens: 200, modelUsed: $request->configuration->model));
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($provider);

        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'openai',
            name: 'OpenAI',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [TextGenerationCapableInterface::class],
            serviceName: 'test.provider',
            container: $container,
        ));
    }

    #[Test]
    public function aDowngradeLogsWhatTheRequestedConfigurationWouldHaveEmitted(): void
    {
        $this->createConfiguration('gpt-4o', ['default' => 1, 'grid_intensity' => '100.00']);
        $this->createConfiguration('gpt-4o-mini');
        $this->seedHistory('gpt-4o', 0.01);
        $this->seedHistory('gpt-4o-mini', 0.0001);

        $chain = $this->get(ProviderResolver::class)->buildFallbackChain(TextGenerationCapableInterface::class);
        $this->get(AiMiddlewarePipeline::class)->dispatchWithFallback(
            new TextGenerationRequest(configuration: $chain->getPrimary()->configuration, prompt: 'Hi'),
            $chain,
        );

        $row = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log')
            ->select(['*'], 'tx_aim_request_log', [], [], ['uid' => 'DESC'], 1)
            ->fetchAssociative();
        self::assertSame('gpt-4o-mini', $row['model_used'], 'The premise of this test is that the route happened.');
        self::assertNotNull($row['co2_g_avoided_min'], 'The routing decision did not reach the footprint estimate.');
        self::assertGreaterThan(0.0, (float)$row['co2_g_avoided_max']);
        $requested = json_decode((string)$row['impact_details'], true)['requestedModel'];
        self::assertSame('gpt-4o', $requested['model']);
        self::assertEqualsWithDelta(100.0, $requested['co2Grams']['max'] / $requested['energyWh']['max'] * 1000, 1e-6, 'The grid of the requested configuration was not used.');
    }

    private function seedHistory(string $model, float $cost): void
    {
        $logRepository = $this->get(RequestLogRepository::class);
        for ($i = 0; $i < 12; $i++) {
            $logRepository->log([
                'crdate' => time(),
                'request_type' => 'TextGenerationRequest',
                'provider_identifier' => 'openai',
                'model_used' => $model,
                'success' => 1,
                'cost' => $cost,
                'duration_ms' => 1000,
                'total_tokens' => 100,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createConfiguration(string $model, array $overrides = []): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration')->insert('tx_aim_configuration', array_merge([
            'pid' => 0,
            'ai_provider' => 'openai',
            'title' => $model,
            'model' => $model,
            'default' => 0,
            'rerouting_allowed' => 1,
            'accepts_rerouted_requests' => 1,
            'be_groups' => '',
        ], $overrides));
    }
}
