<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\DataHandling;

use B13\Aim\Domain\Repository\PagePromptFragmentRepository;
use B13\Aim\Prompt\PromptFragmentScope;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * `versioningWS_alwaysAllowLiveEdit` next to `versioningWS` let an edit made
 * in a workspace go straight to live on TYPO3 12.4 to 14.3.7. Without it,
 * DataHandler versions both tables, and the Prompt Management module reads
 * those versions within the workspace only.
 */
final class PromptFragmentWorkspaceVersioningTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private int $liveFragmentUid;
    private int $otherFragmentUid;
    private int $liveAssignmentUid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => 1, 'pid' => 0, 'title' => 'Draft']);
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Standard page']);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Live fragment', 'prompt' => 'Live tone.', 'scope' => PromptFragmentScope::All->value]);
        $this->liveFragmentUid = (int)$fragments->lastInsertId();
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Other fragment', 'prompt' => 'Other tone.', 'scope' => PromptFragmentScope::All->value]);
        $this->otherFragmentUid = (int)$fragments->lastInsertId();

        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 1, 'parent_page' => 1, 'fragment' => $this->liveFragmentUid, 'inherit_to_subpages' => 1]);
        $this->liveAssignmentUid = (int)$assignments->lastInsertId();
    }

    #[Test]
    public function editsMadeInAWorkspaceAreVersionedAndLeaveLiveUntouched(): void
    {
        $this->processInWorkspace([
            'tx_aim_prompt_fragment' => [
                $this->liveFragmentUid => ['prompt' => 'Draft tone.'],
            ],
            'tx_aim_page_prompt_fragment' => [
                $this->liveAssignmentUid => ['fragment' => $this->otherFragmentUid, 'inherit_to_subpages' => 0],
            ],
        ]);

        $liveFragment = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')
            ->select(['prompt'], 'tx_aim_prompt_fragment', ['uid' => $this->liveFragmentUid])
            ->fetchAssociative();
        self::assertSame('Live tone.', $liveFragment['prompt']);
        $liveAssignment = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment')
            ->select(['fragment', 'inherit_to_subpages'], 'tx_aim_page_prompt_fragment', ['uid' => $this->liveAssignmentUid])
            ->fetchAssociative();
        self::assertSame($this->liveFragmentUid, (int)$liveAssignment['fragment']);
        self::assertSame(1, (int)$liveAssignment['inherit_to_subpages']);

        self::assertSame(1, $this->countVersions('tx_aim_prompt_fragment', $this->liveFragmentUid));
        self::assertSame(1, $this->countVersions('tx_aim_page_prompt_fragment', $this->liveAssignmentUid));
    }

    #[Test]
    public function promptManagementReadsTheWorkspaceVersionsOnlyWithinTheWorkspace(): void
    {
        $this->processInWorkspace([
            'tx_aim_page_prompt_fragment' => [
                $this->liveAssignmentUid => ['fragment' => $this->otherFragmentUid, 'inherit_to_subpages' => 0],
            ],
        ]);
        $repository = $this->get(PagePromptFragmentRepository::class);

        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $assignments = $repository->findAssignmentsForPage(1);
        self::assertCount(1, $assignments);
        self::assertSame($this->liveAssignmentUid, $assignments[0]['uid']);
        self::assertSame('Other fragment', $assignments[0]['title']);
        self::assertSame(['total' => 1, 'inherited' => 0], $repository->countAllAssignments());

        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        self::assertSame('Live fragment', $repository->findAssignmentsForPage(1)[0]['title']);
        self::assertSame(['total' => 1, 'inherited' => 1], $repository->countAllAssignments());
    }

    #[Test]
    public function anAssignmentDeletedInAWorkspaceIsGoneThereOnly(): void
    {
        $this->processInWorkspace([], [
            'tx_aim_page_prompt_fragment' => [
                $this->liveAssignmentUid => ['delete' => 1],
            ],
        ]);
        $repository = $this->get(PagePromptFragmentRepository::class);

        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        self::assertSame([], $repository->findAssignmentsForPage(1));
        self::assertSame(['total' => 0, 'inherited' => 0], $repository->countAllAssignments());

        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        self::assertCount(1, $repository->findAssignmentsForPage(1));
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap
     * @param array<string, array<int, array<string, mixed>>> $commandMap
     */
    private function processInWorkspace(array $dataMap, array $commandMap = []): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(1);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, $commandMap, $backendUser);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, implode('; ', $dataHandler->errorLog));
    }

    private function countVersions(string $table, int $liveUid): int
    {
        return $this->getConnectionPool()->getConnectionForTable($table)
            ->count('uid', $table, ['t3ver_oid' => $liveUid, 't3ver_wsid' => 1]);
    }
}
