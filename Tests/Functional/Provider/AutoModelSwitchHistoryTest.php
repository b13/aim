<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Provider;

use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The auto model switch ranks the catalog's models by the cost in the
 * request log, which stores the name the provider reported.
 */
final class AutoModelSwitchHistoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    /**
     * The switched requests are logged against the model the switch started
     * from, so only the configured model they ran on ties them to the candidate.
     */
    #[Test]
    public function theHistoryOfASwitchToADatedModelRanksThatModel(): void
    {
        $this->addProviderWithEmbeddingModels(['embed-small-2024-01-01', 'embed-small-2024-07-18']);
        $this->seedHistory('embed-small-2024-07-18', 0.0001, 'chat-model', 'model_switch', 'embed-small-2024-07-18');
        $this->seedHistory('embed-small-2024-01-01', 0.01, 'chat-model', 'model_switch', 'embed-small-2024-01-01');

        $resolved = $this->get(ProviderResolver::class)->resolveForCapability(EmbeddingCapableInterface::class);

        self::assertSame('embed-small-2024-07-18', $resolved->configuration->model);
    }

    #[Test]
    public function aDatedModelThatFailedAfterASwitchIsSkipped(): void
    {
        $this->addProviderWithEmbeddingModels(['embed-small-2024-01-01', 'embed-small-2024-07-18']);
        $this->seedHistory('embed-small-2024-01-01', 0.0, 'chat-model', 'model_switch', 'embed-small-2024-01-01', success: 0);

        $resolved = $this->get(ProviderResolver::class)->resolveForCapability(EmbeddingCapableInterface::class);

        self::assertSame('embed-small-2024-07-18', $resolved->configuration->model);
    }

    /**
     * @param list<string> $embeddingModels
     */
    private function addProviderWithEmbeddingModels(array $embeddingModels): void
    {
        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'testprovider',
            name: 'Test Provider',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [TextGenerationCapableInterface::class, EmbeddingCapableInterface::class],
            serviceName: 'test.provider',
            container: $this->createMock(ContainerInterface::class),
            modelCapabilities: ['chat-model' => [TextGenerationCapableInterface::class]]
                + array_fill_keys($embeddingModels, [EmbeddingCapableInterface::class]),
        ));
        $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration')->insert('tx_aim_configuration', [
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => 'chat',
            'model' => 'chat-model',
            'default' => 1,
            'auto_model_switch' => 1,
            'be_groups' => '',
        ]);
    }

    private function seedHistory(
        string $modelUsed,
        float $cost,
        string $modelRequested,
        string $rerouteType = '',
        string $modelConfigured = '',
        int $success = 1,
    ): void
    {
        $logRepository = $this->get(RequestLogRepository::class);
        for ($i = 0; $i < 3; $i++) {
            $logRepository->log([
                'crdate' => time(),
                'request_type' => 'EmbeddingRequest',
                'provider_identifier' => 'testprovider',
                'model_requested' => $modelRequested,
                'model_used' => $modelUsed,
                'model_configured' => $modelConfigured,
                'reroute_type' => $rerouteType,
                'success' => $success,
                'cost' => $cost,
            ]);
        }
    }
}
