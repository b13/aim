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
use B13\Aim\Exception\ProviderNotFoundException;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Auto model switch reuses a configuration's credential with a different model,
 * so it has to honour that configuration's be_groups like every other path that
 * picks a provider the caller did not name.
 */
final class AutoModelSwitchAccessTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'testprovider',
            name: 'Test Provider',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [TextGenerationCapableInterface::class, EmbeddingCapableInterface::class],
            serviceName: 'test.provider',
            container: $this->createMock(ContainerInterface::class),
            modelCapabilities: [
                'chat-model' => [TextGenerationCapableInterface::class],
                'embed-model' => [EmbeddingCapableInterface::class],
            ],
        ));
    }

    #[Test]
    public function aConfigurationTheUserMayNotUseIsNotAutoSwitchedTo(): void
    {
        $this->createConfiguration('for-another-group', ['be_groups' => '99']);
        $this->createBackendUserInGroup(1);

        $this->expectException(ProviderNotFoundException::class);
        $this->get(ProviderResolver::class)->resolveForCapability(EmbeddingCapableInterface::class);
    }

    /**
     * Without the check the restricted configuration wins on being default, and
     * the request then fails at dispatch instead of using the one that works.
     */
    #[Test]
    public function anAccessibleConfigurationIsUsedInsteadOfARestrictedDefault(): void
    {
        $this->createConfiguration('restricted-default', ['default' => 1, 'be_groups' => '99']);
        $open = $this->createConfiguration('open-to-everyone');
        $this->createBackendUserInGroup(1);

        $resolved = $this->get(ProviderResolver::class)->resolveForCapability(EmbeddingCapableInterface::class);

        self::assertSame($open, $resolved->configuration->uid);
        self::assertSame('embed-model', $resolved->configuration->model);
    }

    #[Test]
    public function aUserInTheAllowedGroupStillGetsTheSwitch(): void
    {
        $uid = $this->createConfiguration('for-my-group', ['be_groups' => '1']);
        $this->createBackendUserInGroup(1);

        $resolved = $this->get(ProviderResolver::class)->resolveForCapability(EmbeddingCapableInterface::class);

        self::assertSame($uid, $resolved->configuration->uid);
        self::assertSame('embed-model', $resolved->configuration->model);
    }

    /**
     * No backend user at all, as in a Scheduler or CLI run.
     */
    #[Test]
    public function aRunWithoutABackendUserIsNotBlocked(): void
    {
        $uid = $this->createConfiguration('for-another-group', ['be_groups' => '99']);
        unset($GLOBALS['BE_USER']);

        $resolved = $this->get(ProviderResolver::class)->resolveForCapability(EmbeddingCapableInterface::class);

        self::assertSame($uid, $resolved->configuration->uid);
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
            'model' => 'chat-model',
            'default' => 0,
            'auto_model_switch' => 1,
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
