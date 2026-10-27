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
use TYPO3\CMS\Core\Database\ConnectionPool;
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

    /**
     * Cheaper is not free if the editor waits twice as long for it. The profile
     * carries avg_duration_ms; before this it was loaded and ignored.
     */
    #[Test]
    public function aCheaperButMuchSlowerModelIsNotRoutedTo(): void
    {
        $expensive = $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $this->createConfiguration(self::CHEAP_MODEL);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01, 1000);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001, 2500);

        $this->dispatch('Hi');

        self::assertSame([$expensive], $this->provider->seen, 'A model 2.5x slower was routed to anyway.');
    }

    #[Test]
    public function aCheaperAndOnlySlightlySlowerModelIsStillRoutedTo(): void
    {
        $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $cheap = $this->createConfiguration(self::CHEAP_MODEL);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01, 1000);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001, 1900);

        $this->dispatch('Hi');

        self::assertSame([$cheap], $this->provider->seen, 'The latency gate is too strict.');
    }

    /**
     * Providers report dated model names (gpt-4o-mini-2024-07-18) where the
     * configuration says gpt-4o-mini.
     */
    #[Test]
    public function aSimplePromptIsDowngradedWhenTheHistoryHasDatedModelNames(): void
    {
        $this->createConfiguration('gpt-4o', ['default' => 1]);
        $cheap = $this->createConfiguration('gpt-4o-mini');
        $this->seedHistory('gpt-4o-2024-08-06', 0.01, configured: 'gpt-4o');
        $this->seedHistory('gpt-4o-mini-2024-07-18', 0.0001, configured: 'gpt-4o-mini');

        $this->dispatch('Hi');

        self::assertSame([$cheap], $this->provider->seen, 'Dated model names in the history kept smart routing from downgrading.');
    }

    #[Test]
    public function historyUnderDatedAndUndatedNamesCountsForTheSameModel(): void
    {
        $this->createConfiguration('gpt-4o', ['default' => 1]);
        $cheap = $this->createConfiguration('gpt-4o-mini');
        $this->seedHistory('gpt-4o', 0.01, configured: 'gpt-4o');
        // 6 + 6: neither name alone reaches the 10 requests routing needs
        $this->seedHistory('gpt-4o-mini', 0.0001, 1000, 6, configured: 'gpt-4o-mini');
        $this->seedHistory('gpt-4o-mini-2024-07-18', 0.0001, 1000, 6, configured: 'gpt-4o-mini');

        $this->dispatch('Hi');

        self::assertSame([$cheap], $this->provider->seen);
    }

    /**
     * A downgrade is logged against the model it was routed away from, and
     * the cheap model reports a name no configuration contains. Counted under
     * that name, the downgrades formed a profile cheaper than the model's own,
     * which no configuration could take, and routing stopped downgrading.
     */
    #[Test]
    public function downgradesKeepCountingForTheConfigurationTheyRanOn(): void
    {
        $this->createConfiguration('claude-sonnet-4-0', ['default' => 1]);
        $cheap = $this->createConfiguration('claude-3-5-haiku-latest');
        $this->seedHistory('claude-sonnet-4-20250514', 0.01, configured: 'claude-sonnet-4-0');
        $this->seedHistory('claude-3-5-haiku-20241022', 0.0002, configured: 'claude-3-5-haiku-latest');
        $this->seedHistory('claude-3-5-haiku-20241022', 0.0001, configured: 'claude-3-5-haiku-latest', requested: 'claude-sonnet-4-0', rerouteType: 'model_switch');

        $this->dispatch('Hi');

        self::assertSame([$cheap], $this->provider->seen);
    }

    /**
     * Older log entries carry no configured model, so their history can still
     * end up under a name no configuration contains.
     */
    #[Test]
    public function aCheaperModelWithoutAConfigurationDoesNotStopTheDowngrade(): void
    {
        $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $cheap = $this->createConfiguration(self::CHEAP_MODEL);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01);
        $this->seedHistory(self::CHEAP_MODEL, 0.001);
        $this->seedHistory('unconfigured-model', 0.0001);

        $this->dispatch('Hi');

        self::assertSame([$cheap], $this->provider->seen);
    }

    /**
     * The downgrade used to be visible only in the system log: the request log
     * recorded a plain request against the cheaper model.
     */
    #[Test]
    public function theDowngradeIsRecordedAsARerouteWithItsNumbers(): void
    {
        $this->createConfiguration(self::EXPENSIVE_MODEL, ['default' => 1]);
        $this->createConfiguration(self::CHEAP_MODEL);
        $this->seedHistory(self::EXPENSIVE_MODEL, 0.01, 1000);
        $this->seedHistory(self::CHEAP_MODEL, 0.0001, 900);

        $this->dispatch('Hi');

        $row = $this->latestLogRow();
        self::assertSame(1, (int)$row['rerouted'], 'The downgrade was not recorded as a reroute.');
        self::assertSame('model_switch', $row['reroute_type']);
        self::assertSame(self::EXPENSIVE_MODEL, $row['model_requested'], 'The original model was not kept.');
        self::assertSame(self::CHEAP_MODEL, $row['model_configured']);
        self::assertStringContainsString('smart routing', $row['reroute_reason']);
        self::assertStringContainsString('cost 0.010000 -> 0.000100', $row['reroute_reason']);
        self::assertStringContainsString('duration 1000 -> 900 ms', $row['reroute_reason']);
        self::assertStringContainsString('tokens', $row['reroute_reason']);
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

    /**
     * @return array<string, mixed>
     */
    private function latestLogRow(): array
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_aim_request_log');
        $rows = $connection->select(['*'], 'tx_aim_request_log', [], [], ['uid' => 'DESC'], 1)->fetchAllAssociative();
        self::assertNotEmpty($rows, 'No request was logged at all.');
        return $rows[0];
    }

    private function seedHistory(
        string $model,
        float $cost,
        int $durationMs = 1000,
        int $count = 12,
        string $requested = '',
        string $configured = '',
        string $rerouteType = '',
    ): void
    {
        $logRepository = $this->get(RequestLogRepository::class);
        for ($i = 0; $i < $count; $i++) {
            $logRepository->log([
                'crdate' => time(),
                'request_type' => 'TextGenerationRequest',
                'provider_identifier' => 'testprovider',
                'model_requested' => $requested,
                'model_used' => $model,
                'model_configured' => $configured,
                'reroute_type' => $rerouteType,
                'success' => 1,
                'cost' => $cost,
                'duration_ms' => $durationMs,
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
