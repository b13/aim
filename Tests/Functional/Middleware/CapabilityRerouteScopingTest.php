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
use B13\Aim\Capability\VisionCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ResolvedProvider;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Swapping the provider because it lacks a capability is the fourth path that
 * can put a request on a configuration the caller never named, and it runs
 * inside AccessControlMiddleware, so that middleware never re-checks the
 * destination. It therefore has to apply the same three restrictions the
 * fallback chain, smart routing and auto model switch do.
 */
final class CapabilityRerouteScopingTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private TextOnlyProvider $textOnly;

    private VisionProvider $visionProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->textOnly = new TextOnlyProvider();
        $this->visionProvider = new VisionProvider();

        $registry = $this->get(AiProviderRegistry::class);
        $registry->addProvider($this->manifest('textonly', $this->textOnly, [TextGenerationCapableInterface::class]));
        $registry->addProvider($this->manifest('visioncapable', $this->visionProvider, [VisionCapableInterface::class]));
    }

    #[Test]
    public function aRequestIsReroutedToACapableConfiguration(): void
    {
        $source = $this->createConfiguration('textonly', 'text-model');
        $this->createConfiguration('visioncapable', 'vision-model');

        $response = $this->dispatchVision($source);

        self::assertTrue($response->isSuccessful());
        self::assertSame('answered by vision provider', $response->content);
    }

    /**
     * The source is pinned, so README's promise is that the request fails
     * rather than moving.
     */
    #[Test]
    public function aPinnedSourceIsNotRerouted(): void
    {
        $source = $this->createConfiguration('textonly', 'text-model', ['rerouting_allowed' => 0]);
        $this->createConfiguration('visioncapable', 'vision-model');

        $response = $this->dispatchVision($source);

        self::assertFalse($response->isSuccessful());
        self::assertFalse($this->visionProvider->wasCalled, 'A pinned configuration leaked its payload.');
    }

    #[Test]
    public function aDestinationThatRefusesReroutedRequestsIsNotUsed(): void
    {
        $source = $this->createConfiguration('textonly', 'text-model');
        $this->createConfiguration('visioncapable', 'vision-model', ['accepts_rerouted_requests' => 0]);

        $response = $this->dispatchVision($source);

        self::assertFalse($response->isSuccessful());
        self::assertFalse($this->visionProvider->wasCalled, 'A reserved configuration received rerouted traffic.');
    }

    #[Test]
    public function aDestinationTheUserMayNotUseIsNotUsed(): void
    {
        $source = $this->createConfiguration('textonly', 'text-model');
        $this->createConfiguration('visioncapable', 'vision-model', ['be_groups' => '99']);
        $this->createBackendUserInGroup(1);

        $response = $this->dispatchVision($source);

        self::assertFalse($response->isSuccessful());
        self::assertFalse($this->visionProvider->wasCalled, 'A group-restricted configuration was used anyway.');
    }

    private function dispatchVision(int $sourceUid): TextResponse
    {
        $configuration = $this->get(ProviderConfigurationRepository::class)->findByUid($sourceUid);
        self::assertNotNull($configuration);

        $resolved = new ResolvedProvider(
            $this->get(AiProviderRegistry::class)->getProvider('textonly'),
            $configuration,
        );

        return $this->get(AiMiddlewarePipeline::class)->dispatch(
            new VisionRequest(
                configuration: $configuration,
                imageData: base64_encode('not-a-real-image'),
                mimeType: 'image/png',
                prompt: 'Describe this image in enough words to avoid trivial classification.',
            ),
            $resolved,
        );
    }

    /**
     * @param list<class-string> $capabilities
     */
    private function manifest(string $identifier, AiProviderInterface $instance, array $capabilities): AiProviderManifest
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($instance);

        return new AiProviderManifest(
            identifier: $identifier,
            name: $identifier,
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: $capabilities,
            serviceName: 'test.' . $identifier,
            container: $container,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createConfiguration(string $provider, string $model, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge([
            'pid' => 0,
            'ai_provider' => $provider,
            'title' => $provider,
            'model' => $model,
            'default' => 0,
            'rerouting_allowed' => 1,
            'accepts_rerouted_requests' => 1,
            'be_groups' => '',
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function createBackendUserInGroup(int $groupUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')
            ->insert('be_groups', ['uid' => $groupUid, 'title' => 'group ' . $groupUid]);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'editor', 'admin' => 0, 'usergroup' => (string)$groupUid]);
        $this->setUpBackendUser(1);
    }
}

/**
 * Declares text generation only, so a VisionRequest reaches the reroute branch.
 */
final class TextOnlyProvider implements AiProviderInterface, TextGenerationCapableInterface
{
    public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
    {
        return new TextResponse('answered by text provider');
    }
}

final class VisionProvider implements AiProviderInterface, VisionCapableInterface
{
    public bool $wasCalled = false;

    public function processVisionRequest(VisionRequest $request): TextResponse
    {
        $this->wasCalled = true;

        return new TextResponse('answered by vision provider');
    }
}
