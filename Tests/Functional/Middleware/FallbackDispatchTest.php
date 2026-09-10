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
use B13\Aim\Governance\RateLimitCounter;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Model fallback through the real pipeline, not just the chain builder.
 */
final class FallbackDispatchTest extends FunctionalTestCase
{
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
    public function aFailingPrimaryIsRetriedAgainstTheNextConfiguration(): void
    {
        $primary = $this->createConfiguration('primary', ['default' => 1]);
        $fallback = $this->createConfiguration('fallback');
        $this->provider->failFor = [$primary];

        $response = $this->dispatch();

        self::assertTrue($response->isSuccessful());
        self::assertSame('answered by fallback', $response->content);
        self::assertSame([$primary, $fallback], $this->provider->seen, 'The retry never reached the second configuration.');
    }

    /**
     * An empty completion counts as unsuccessful, which is the case that makes
     * fallback matter for a local model that times out or returns nothing.
     */
    #[Test]
    public function anEmptyCompletionAlsoTriggersTheRetry(): void
    {
        $primary = $this->createConfiguration('primary', ['default' => 1]);
        $fallback = $this->createConfiguration('fallback');
        $this->provider->emptyFor = [$primary];

        $response = $this->dispatch();

        self::assertSame('answered by fallback', $response->content);
        self::assertSame([$primary, $fallback], $this->provider->seen);
    }

    /**
     * The scoping rules only bite when an operator has opted in. A default
     * install never sets rerouting_allowed, so the column default (1) applies
     * and the chain has to stay full.
     */
    #[Test]
    public function aDefaultInstallThatSetsNothingStillFallsBackAcrossEveryConfiguration(): void
    {
        $primary = $this->createConfigurationWithoutGovernanceColumns('primary', true);
        $second = $this->createConfigurationWithoutGovernanceColumns('second', false);
        $third = $this->createConfigurationWithoutGovernanceColumns('third', false);
        $this->provider->failFor = [$primary, $second];

        $response = $this->dispatch();

        self::assertTrue($response->isSuccessful());
        self::assertSame([$primary, $second, $third], $this->provider->seen, 'An untouched install lost fallback providers.');
    }

    #[Test]
    public function aPinnedPrimaryFailsWithoutReachingAnyOtherProvider(): void
    {
        $primary = $this->createConfiguration('local-ollama', ['default' => 1, 'rerouting_allowed' => 0]);
        $this->createConfiguration('cloud');
        $this->provider->failFor = [$primary];

        $response = $this->dispatch();

        self::assertFalse($response->isSuccessful());
        self::assertSame([$primary], $this->provider->seen, 'A pinned configuration leaked its payload to another provider.');
    }

    #[Test]
    public function aPinnedConfigurationCanStillReceiveAnotherConfigurationsTraffic(): void
    {
        $primary = $this->createConfiguration('cloud', ['default' => 1]);
        $pinned = $this->createConfiguration('local-ollama', [
            'rerouting_allowed' => 0,
            'accepts_rerouted_requests' => 1,
        ]);
        $this->provider->failFor = [$primary];

        $response = $this->dispatch();

        self::assertTrue($response->isSuccessful());
        self::assertSame([$primary, $pinned], $this->provider->seen);
    }

    #[Test]
    public function aConfigurationThatRefusesReroutedRequestsIsNeverReached(): void
    {
        $primary = $this->createConfiguration('cloud', ['default' => 1]);
        $this->createConfiguration('hr-only-model', ['accepts_rerouted_requests' => 0]);
        $this->provider->failFor = [$primary];

        $response = $this->dispatch();

        self::assertFalse($response->isSuccessful());
        self::assertSame([$primary], $this->provider->seen, 'General traffic reached a model reserved for particular content.');
    }

    /**
     * AccessControlMiddleware sits inside this middleware, so it is entered
     * once per hop. Counting each of them would make the effective limit the
     * configured one divided by the chain length.
     */
    #[Test]
    public function theRateLimiterCountsOneSlotPerDispatchNotPerFallbackHop(): void
    {
        $primary = $this->createConfiguration('primary', ['default' => 1]);
        $second = $this->createConfiguration('second');
        $this->createConfiguration('third');
        $this->provider->failFor = [$primary, $second];
        $this->createBackendUser();

        $counter = $this->get(RateLimitCounter::class);
        $this->dispatch();

        // One for the dispatch, one for this probe.
        self::assertSame(2, $counter->record($this->userId), 'A fallback sweep burned a slot per hop.');
    }

    /**
     * A governance refusal is about the caller, so every fallback would refuse
     * it too. Sweeping the chain only burns slots and misattributes the error
     * to the last configuration tried.
     */
    #[Test]
    public function aGovernanceDenialIsNotRetriedAgainstTheWholeChain(): void
    {
        $primary = $this->createConfiguration('primary', ['default' => 1, 'be_groups' => '99']);
        $this->createConfiguration('second');
        $this->createConfiguration('third');
        $this->createBackendUser();

        $response = $this->dispatch();

        self::assertFalse($response->isSuccessful());
        self::assertSame([], $this->provider->seen, 'A denied request still reached a provider.');
        self::assertStringContainsString('permission', $response->errors[0] ?? '');
    }

    /**
     * The privacy level is read from the configuration of the current attempt,
     * so without a floor a primary set to log nothing falls back to a standard
     * configuration and that attempt writes the full prompt.
     */
    #[Test]
    public function aPrivacyLevelOfNoneSurvivesTheFallback(): void
    {
        $primary = $this->createConfiguration('confidential', ['default' => 1, 'privacy_level' => 'none']);
        $this->createConfiguration('cloud', ['privacy_level' => 'standard']);
        $this->provider->failFor = [$primary];

        $response = $this->dispatch();

        self::assertTrue($response->isSuccessful(), 'The fallback should still answer.');
        $rows = $this->getConnectionPool()
            ->getConnectionForTable('tx_aim_request_log')
            ->select(['*'], 'tx_aim_request_log')
            ->fetchAllAssociative();

        self::assertSame([], $rows, 'The originating configuration asked for no logging at all.');
    }

    private int $userId = 0;

    private function createBackendUser(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        $connection->insert('be_users', ['username' => 'editor', 'admin' => 0, 'usergroup' => '1']);
        $this->userId = (int)$connection->lastInsertId();
        $this->getConnectionPool()->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => 1, 'title' => 'group 1']);
        $this->setUpBackendUser($this->userId);
    }

    private function dispatch(): TextResponse
    {
        $resolver = $this->get(ProviderResolver::class);
        $chain = $resolver->buildFallbackChain(TextGenerationCapableInterface::class);

        return $this->get(AiMiddlewarePipeline::class)->dispatchWithFallback(
            new TextGenerationRequest(
                configuration: $chain->getPrimary()->configuration,
                prompt: 'Anything at all, long enough not to be reclassified as trivial by the router.',
            ),
            $chain,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createConfiguration(string $title, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration');
        $connection->insert('tx_aim_configuration', array_merge([
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => $title,
            'model' => 'model-' . $title,
            'default' => 0,
            'rerouting_allowed' => 1,
            'accepts_rerouted_requests' => 1,
            'be_groups' => '',
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    /**
     * Deliberately omits rerouting_allowed and be_groups so the schema defaults
     * apply, the way a row created through the backend without touching the
     * governance palette would look.
     */
    private function createConfigurationWithoutGovernanceColumns(string $title, bool $isDefault): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration');
        $connection->insert('tx_aim_configuration', [
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => $title,
            'model' => 'model-' . $title,
            'default' => $isDefault ? 1 : 0,
        ]);

        return (int)$connection->lastInsertId();
    }
}

/**
 * @internal
 */
final class RecordingProvider implements AiProviderInterface, TextGenerationCapableInterface
{
    /** @var list<int> */
    public array $seen = [];

    /** @var list<int> */
    public array $failFor = [];

    /** @var list<int> */
    public array $emptyFor = [];

    public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
    {
        $uid = $request->configuration->uid;
        $this->seen[] = $uid;

        if (in_array($uid, $this->failFor, true)) {
            return new TextResponse('', errors: ['provider ' . $uid . ' is down']);
        }
        if (in_array($uid, $this->emptyFor, true)) {
            return new TextResponse('');
        }

        return new TextResponse('answered by ' . $request->configuration->title);
    }
}
