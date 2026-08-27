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

use B13\Aim\Prompt\PromptFragmentScope;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Every other functional test in this suite seeds tx_aim_prompt_fragment /
 * tx_aim_page_prompt_fragment fixtures via direct SQL inserts
 * (ConnectionPool::insert()), which bypasses DataHandler entirely and so
 * never exercised the real, editor-facing "assign a fragment via the
 * backend form" path. That path went through
 * DataHandler::isRecordAllowedOnPage(), which rejects inserting a child
 * table on a page unless the table is listed in
 * pages.ctrl.defaultAllowedRecordTypes (empty by default) for that page's
 * doktype, and neither table opted in via the ctrl.security.
 * ignorePageTypeRestriction flag tt_content itself relies on for the
 * identical reason, so every single insert would fail in production with
 * "Attempt to insert record on pages:{pid} where table
 * tx_aim_page_prompt_fragment is not allowed", on completely ordinary
 * (doktype=1, Standard) pages. This test exercises the real DataHandler
 * insert path (both the library fragment AND its assignment, wired
 * together via NEW-id relations the same way the real "+ create new
 * fragment" inline flow would) so a regression in DataHandler's own
 * acceptance of this datamap shape fails loudly instead of silently, the
 * way the direct-SQL-fixture tests elsewhere in this suite never would.
 *
 * What this does NOT cover: the actual "+" suggest-wizard/AddRecord UI
 * round-trip itself (FormEngine, the AJAX endpoint, DisableAddRecordOnUnsavedAssignment)
 * - only a hand-built datamap with the same shape that flow would
 * eventually submit. A regression in the wizard's own JS/PHP wiring, as
 * opposed to DataHandler's acceptance of the resulting data, would not be
 * caught here.
 */
final class PromptFragmentInsertTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function aFragmentAndItsPageAssignmentCanBeInsertedViaDataHandlerOnAnOrdinaryStandardPage(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'username' => 'admin',
            'admin' => 1,
        ]);
        $backendUser = $this->setUpBackendUser(1);

        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Standard page',
            'doktype' => 1,
        ]);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'tx_aim_prompt_fragment' => [
                'NEWfragment1' => [
                    'pid' => 1,
                    'title' => 'Fragment via DataHandler',
                    'prompt' => 'Be concise.',
                    'scope' => PromptFragmentScope::Text->value,
                ],
            ],
            'tx_aim_page_prompt_fragment' => [
                'NEWassignment1' => [
                    'pid' => 1,
                    'parent_page' => 1,
                    'fragment' => 'NEWfragment1',
                ],
            ],
        ], [], $backendUser);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog, 'DataHandler rejected the insert: ' . implode('; ', $dataHandler->errorLog));
        self::assertCount(2, $dataHandler->substNEWwithIDs, 'Not both records were actually created.');

        $fragmentUid = (int)$dataHandler->substNEWwithIDs['NEWfragment1'];
        $fragmentRow = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')
            ->select(['uid'], 'tx_aim_prompt_fragment', ['uid' => $fragmentUid])
            ->fetchAssociative();
        self::assertNotFalse($fragmentRow, 'The fragment record does not exist in the database after processing.');

        $assignmentUid = (int)$dataHandler->substNEWwithIDs['NEWassignment1'];
        $assignmentRow = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment')
            ->select(['uid', 'fragment'], 'tx_aim_page_prompt_fragment', ['uid' => $assignmentUid])
            ->fetchAssociative();
        self::assertNotFalse($assignmentRow, 'The assignment record does not exist in the database after processing.');
        self::assertSame($fragmentUid, (int)$assignmentRow['fragment'], 'The assignment does not point at the newly created fragment.');
    }
}
