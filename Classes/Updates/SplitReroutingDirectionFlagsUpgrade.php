<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Updates;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Sets accepts_rerouted_requests = 0 wherever rerouting_allowed is already 0.
 *
 * rerouting_allowed used to govern both directions, so without this the new
 * column's default of 1 would open up existing pinned configurations on
 * upgrade.
 *
 * Runs once, recorded in the registry. The row shape it looks for,
 * rerouting_allowed = 0 with accepts_rerouted_requests = 1, is also a
 * legitimate thing to configure by hand (a local model pinned for confidential
 * work that still absorbs an outage of the cloud default), and the new column's
 * default of 1 leaves no way to tell that apart from "never migrated". Without
 * the marker, configuring that combination would make this wizard report work
 * again and a re-run would silently undo it.
 */
#[UpgradeWizard('aimSplitReroutingDirectionFlags')]
final class SplitReroutingDirectionFlagsUpgrade implements UpgradeWizardInterface
{
    private const TABLE = 'tx_aim_configuration';

    private const REGISTRY_NAMESPACE = 'tx_aim';
    private const REGISTRY_KEY = 'splitReroutingDirectionFlagsDone';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Registry $registry,
    ) {}

    public function getTitle(): string
    {
        return '[AiM] Preserve rerouting restrictions after the flag split';
    }

    public function getDescription(): string
    {
        return 'Sets accepts_rerouted_requests = 0 on provider configurations that already had '
            . 'rerouting_allowed = 0. Those configurations were previously excluded as rerouting '
            . 'and fallback destinations too, and without this they would start accepting other '
            . 'configurations\' traffic.';
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * Core builds the wizard list by calling this before it enforces
     * DatabaseUpdatedPrerequisite, so it has to answer rather than throw when
     * the column does not exist yet.
     *
     * A missing column answers true, not false: UpgradeWizardRunCommand marks
     * every wizard that answers false as done and never revisits it, and it
     * collects all wizards before fulfilling the prerequisite, so answering
     * false would retire this migration permanently on a `upgrade:run` issued
     * before the schema update, leaving every pinned configuration accepting
     * other configurations' traffic.
     */
    public function updateNecessary(): bool
    {
        if ($this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, false) === true) {
            return false;
        }

        if (!$this->flagColumnExists()) {
            return true;
        }

        return $this->countAffectedRows() > 0;
    }

    private function flagColumnExists(): bool
    {
        try {
            $columns = $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->createSchemaManager()
                ->listTableColumns(self::TABLE);
        } catch (\Throwable) {
            return false;
        }

        return isset($columns['accepts_rerouted_requests']);
    }

    public function executeUpdate(): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->update(self::TABLE)
            ->set('accepts_rerouted_requests', 0)
            ->where(
                $qb->expr()->eq('rerouting_allowed', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('accepts_rerouted_requests', $qb->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->executeStatement();

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, true);

        return true;
    }

    private function countAffectedRows(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return (int)$qb->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('rerouting_allowed', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('accepts_rerouted_requests', $qb->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
