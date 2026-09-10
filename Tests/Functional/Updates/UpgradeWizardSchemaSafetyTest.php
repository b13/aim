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

use B13\Aim\Updates\SplitEndpointFromApiKeyUpgrade;
use B13\Aim\Updates\SplitReroutingDirectionFlagsUpgrade;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * updateNecessary() must survive a database that has not been updated yet.
 *
 * Core builds the wizard list by calling updateNecessary() on every wizard
 * before it enforces DatabaseUpdatedPrerequisite, and it catches only the three
 * wizard-specific exceptions. A Doctrine error from one wizard therefore aborts
 * the whole Upgrade Wizard panel, the whole `upgrade:run`, and the Reports
 * module, taking unrelated core wizards with it.
 *
 * Note this contract can only be enforced on a real DBMS: SQLite reads a
 * double-quoted unknown column as a string literal rather than erroring, so run
 * this with `runTests.sh -s functional -d mariadb`.
 */
final class UpgradeWizardSchemaSafetyTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_configuration';

    /**
     * Needed for the wizards to be resolvable: on v13 the upgrade-wizard
     * infrastructure lives in EXT:install, on v14 it moved into the core, and
     * without this the container answers ServiceNotFoundException on v13 only.
     */
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function theEndpointSplitWizardSurvivesAMissingEndpointColumn(): void
    {
        $this->seedLegacyRow();
        $this->dropColumn('endpoint');

        self::assertTrue(
            $this->get(SplitEndpointFromApiKeyUpgrade::class)->updateNecessary(),
            'A missing column must answer true: UpgradeWizardRunCommand marks a wizard that '
            . 'answers false as done and never revisits it, and it collects every wizard '
            . 'before it fulfils DatabaseUpdatedPrerequisite.',
        );
    }

    #[Test]
    public function theReroutingFlagWizardSurvivesAMissingFlagColumn(): void
    {
        $this->seedLegacyRow();
        $this->dropColumn('accepts_rerouted_requests');

        self::assertTrue(
            $this->get(SplitReroutingDirectionFlagsUpgrade::class)->updateNecessary(),
            'A missing column must answer true, or `upgrade:run` before the schema update '
            . 'retires this migration permanently and every pinned configuration silently '
            . 'starts accepting other configurations traffic.',
        );
    }

    /**
     * A row that gives each wizard something to consider, so neither can pass by
     * finding an empty table.
     */
    private function seedLegacyRow(): void
    {
        $this->getConnectionPool()->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => 'legacy',
            'api_key' => 'http://localhost:11434',
            'model' => 'test-model',
            'rerouting_allowed' => 0,
        ]);
    }

    private function dropColumn(string $column): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable(self::TABLE)
            ->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN %s', self::TABLE, $column));
    }
}
