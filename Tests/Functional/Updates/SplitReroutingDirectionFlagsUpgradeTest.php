<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Updates;

use B13\Aim\Updates\SplitReroutingDirectionFlagsUpgrade;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The new column defaults to 1, which is wrong for an existing pinned
 * configuration.
 */
final class SplitReroutingDirectionFlagsUpgradeTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function anExistingPinnedConfigurationKeepsRefusingOtherTraffic(): void
    {
        $pinned = $this->insert(reroutingAllowed: false);
        $open = $this->insert(reroutingAllowed: true);

        $subject = $this->get(SplitReroutingDirectionFlagsUpgrade::class);
        self::assertTrue($subject->updateNecessary());
        self::assertTrue($subject->executeUpdate());

        self::assertSame(0, $this->acceptsReroutedRequests($pinned), 'A pinned configuration was opened up by the upgrade.');
        self::assertSame(1, $this->acceptsReroutedRequests($open), 'An unrestricted configuration must not be narrowed.');
        self::assertFalse($subject->updateNecessary(), 'The wizard is not idempotent.');
    }

    #[Test]
    public function anInstallWithNothingPinnedNeedsNoUpdate(): void
    {
        $this->insert(reroutingAllowed: true);

        self::assertFalse($this->get(SplitReroutingDirectionFlagsUpgrade::class)->updateNecessary());
    }

    /**
     * Someone who has already made the decision explicitly must not have it
     * overwritten by the migration.
     */
    #[Test]
    public function anExplicitlyOpenedPinnedConfigurationIsLeftAlone(): void
    {
        $uid = $this->insert(reroutingAllowed: false);
        $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, ['accepts_rerouted_requests' => 0], ['uid' => $uid]);

        self::assertFalse($this->get(SplitReroutingDirectionFlagsUpgrade::class)->updateNecessary());
    }

    /**
     * "rerouting_allowed = 0, accepts_rerouted_requests = 1" is also a
     * combination an operator can configure deliberately, and the new column's
     * default of 1 leaves no way to tell that apart from "never migrated". So
     * the wizard has to remember it ran, or configuring that pair afterwards
     * would make it report work again and a re-run would silently undo it.
     */
    #[Test]
    public function theWizardDoesNotReopenAfterAnOperatorConfiguresThatSamePair(): void
    {
        $this->insert(false);
        $subject = $this->get(SplitReroutingDirectionFlagsUpgrade::class);

        self::assertTrue($subject->updateNecessary());
        $subject->executeUpdate();
        self::assertFalse($subject->updateNecessary());

        // The operator now pins a configuration but still wants it to absorb
        // an outage of the default.
        $this->insert(false);

        self::assertFalse(
            $subject->updateNecessary(),
            'The wizard reopened and would undo a deliberate configuration.',
        );
    }

    private function insert(bool $reroutingAllowed): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => 'probe',
            'model' => 'test-model',
            'rerouting_allowed' => $reroutingAllowed ? 1 : 0,
            'accepts_rerouted_requests' => 1,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function acceptsReroutedRequests(int $uid): int
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['accepts_rerouted_requests'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();
        self::assertNotFalse($row);

        return (int)$row['accepts_rerouted_requests'];
    }
}
