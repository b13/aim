<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Middleware;

use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Smart routing through the real pipeline, including the candidate checks it
 * now shares with the fallback chain via ConfigurationAccess.
 */
final class SmartRoutingDispatchTest extends FunctionalTestCase
{
    private const EXPENSIVE_MODEL = 'expensive-model';
    private const CHEAP_MODEL = 'cheap-model';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private RecordingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new RecordingProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->provider);

        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'testprovider',
            name: 'Test Provider',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [TextGenerationCapableInterface::class],
            serviceName: 'test.provider',
            container: $container,
        ));
    }

    #[Test]
    public function aSimplePromptIsDowngradedToTheCheaperModel(): void
    {
        $expensive = $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $cheap = $this->createConfiguration(self::CHEAP_MODEL);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001);

        $this->dispatch('Hi');

        self::assertSame([$cheap], $this->provider->seen, 'Smart routing did not downgrade to the cheaper configuration.');
        self::assertNotSame([$expensive], $this->provider->seen);
    }

    #[Test]
    public function aPinnedConfigurationIsStillNeverRoutedAwayFrom(): void
    {
        $pinned = $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1, 'rerouting_allowed' => 0]);
        $this->createConfiguration(self::CHEAP_MODEL);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001);

        $this->dispatch('Hi');

        self::assertSame([$pinned], $this->provider->seen);
    }

    /**
     * The candidate check moved out of this middleware into ConfigurationAccess
     * so the fallback chain could reuse it. It has to keep working here.
     */
    #[Test]
    public function aCheaperModelTheUserMayNotUseIsNotRoutedTo(): void
    {
        $expensive = $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $this->createConfiguration(self::CHEAP_MODEL, ['be_groups' => '99']);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001);
        $this->createNonAdminBackendUser();

        $this->dispatch('Hi');

        self::assertSame([$expensive], $this->provider->seen, 'Routing handed the request to a configuration outside the user\'s groups.');
    }

    #[Test]
    public function aCheaperModelThatRefusesReroutedRequestsIsNotRoutedTo(): void
    {
        $expensive = $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $this->createConfiguration(self::CHEAP_MODEL, ['accepts_rerouted_requests' => 0]);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001);

        $this->dispatch('Hi');

        self::assertSame([$expensive], $this->provider->seen);
    }

    /**
     * Pinning a configuration used to exclude it as a routing target as well,
     * because one flag carried both meanings.
     */
    #[Test]
    public function aPinnedCheaperModelCanStillBeRoutedTo(): void
    {
        $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $pinned = $this->createConfiguration(self::CHEAP_MODEL, [
            'rerouting_allowed' => 0,
            'accepts_rerouted_requests' => 1,
        ]);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001);

        $this->dispatch('Hi');

        self::assertSame([$pinned], $this->provider->seen, 'rerouting_allowed is still being read in both directions.');
    }

    private function dispatch(string $prompt): TextResponse
    {
        $resolver = $this->get(ProviderResolver::class);
        $chain = $resolver->buildFallbackChain(TextGenerationCapableInterface::class);

        return $this->get(AiMiddlewarePipeline::class)->dispatchWithFallback(
            new TextGenerationRequest(configuration: $chain->getPrimary()->configuration, prompt: $prompt),
            $chain,
        );
    }

    private function seedHistory(string $model, float $cost): void
    {
        $logRepository = $this->get(RequestLogRepository::class);
        for ($i = 0; $i < 12; $i++) {
            $logRepository->log([
                'crdate' => time(),
                'request_type' => 'TextGenerationRequest',
                'provider_identifier' => 'testprovider',
                'model_used' => $model,
                'success' => 1,
                'cost' => $cost,
                'total_tokens' => 100,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createConfiguration(string $model, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration');
        $connection->insert('tx_aim_configuration', array_merge([
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => $model,
            'model' => $model,
            'default' => 0,
            'rerouting_allowed' => 1,
            'accepts_rerouted_requests' => 1,
            'be_groups' => '',
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function createNonAdminBackendUser(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => 1, 'title' => 'editors']);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'editor', 'admin' => 0, 'usergroup' => '1']);
        $this->setUpBackendUser(1);
    }

    /**
     * The privacy level is read from the configuration of the attempt that
     * gets logged, so routing a request away from a configuration set to log
     * nothing used to write its full prompt and response into the request log,
     * under the destination's own laxer level. The level the caller asked for
     * has to travel with the request, the same way it already does across a
     * fallback chain.
     */
    #[Test]
    public function aRequestFromAConfigurationThatLogsNothingStaysUnloggedAfterARoute(): void
    {
        $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1, 'privacy_level' => 'none']);
        $cheap = $this->createConfiguration(self::CHEAP_MODEL, ['privacy_level' => 'standard']);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001);

        $this->dispatch('Hi');

        self::assertSame([$cheap], $this->provider->seen, 'The premise of this test is that the route happened.');
        self::assertSame(
            [],
            $this->loggedPrompts(),
            'A request the operator asked not to log was logged after being routed elsewhere.',
        );
    }

    /**
     * The seeded cost history carries no prompt, so anything non-empty here
     * comes from the dispatch under test.
     *
     * @return list<string>
     */
    private function loggedPrompts(): array
    {
        $rows = $this->getConnectionPool()->getConnectionForTable('tx_aim_request_log')
            ->select(['request_prompt'], 'tx_aim_request_log')
            ->fetchAllAssociative();

        return array_values(array_filter(array_map(
            static fn(array $row): string => (string)$row['request_prompt'],
            $rows,
        )));
    }
}
