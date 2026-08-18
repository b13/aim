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
 * Every other functional test in this suite seeds tx_aim_prompt_fragment
 * fixtures via direct SQL inserts (ConnectionPool::insert()), which bypasses
 * DataHandler entirely and so never exercised the real, editor-facing "add
 * a fragment via the backend form" path. That path went through
 * DataHandler::isRecordAllowedOnPage(), which rejects inserting a child
 * table on a page unless the table is listed in
 * pages.ctrl.defaultAllowedRecordTypes (empty by default) for that page's
 * doktype — tx_aim_prompt_fragment never opted in via the
 * ctrl.security.ignorePageTypeRestriction flag tt_content itself relies on
 * for the identical reason, so every single insert failed in production
 * with "Attempt to insert record on pages:{pid} where table
 * tx_aim_prompt_fragment is not allowed", on completely ordinary
 * (doktype=1, Standard) pages. This test exercises the real DataHandler
 * insert path so a regression here fails loudly instead of silently, the
 * way the direct-SQL-fixture tests elsewhere in this suite never would.
 */
final class PromptFragmentInsertTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function aFragmentCanBeInsertedViaDataHandlerOnAnOrdinaryStandardPage(): void
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
                'NEW1' => [
                    'pid' => 1,
                    'parent_page' => 1,
                    'title' => 'Fragment via DataHandler',
                    'prompt' => 'Be concise.',
                    'scope' => PromptFragmentScope::Text->value,
                ],
            ],
        ], [], $backendUser);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog, 'DataHandler rejected the insert: ' . implode('; ', $dataHandler->errorLog));
        self::assertNotEmpty($dataHandler->substNEWwithIDs, 'No record was actually created.');

        $newUid = (int)reset($dataHandler->substNEWwithIDs);
        $row = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')
            ->select(['uid'], 'tx_aim_prompt_fragment', ['uid' => $newUid])
            ->fetchAssociative();
        self::assertNotFalse($row, 'The fragment record does not exist in the database after processing.');
    }
}
