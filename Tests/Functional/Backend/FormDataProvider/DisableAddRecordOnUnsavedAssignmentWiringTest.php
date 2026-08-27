<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Backend\FormDataProvider;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * DisableAddRecordOnUnsavedAssignmentTest (unit) covers the class in
 * isolation, which is exactly why the real bug survived: the class was
 * fully implemented and unit-tested, but never registered into
 * `$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord']`
 * in ext_localconf.php, so it never actually ran. This test drives the real
 * FormDataCompiler + TcaDatabaseRecord pipeline (the same one
 * EditDocumentController uses to build the edit form) instead of
 * instantiating the provider directly, so a future un-registration would
 * fail this test even though the class itself still works perfectly well
 * on its own.
 */
final class DisableAddRecordOnUnsavedAssignmentWiringTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function theRealFormEnginePipelineHidesAddRecordForABrandNewAssignment(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Siteroot',
        ]);
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'pid' => 0,
            'username' => 'admin',
            'admin' => 1,
        ]);
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $formData = $this->get(FormDataCompiler::class)->compile(
            [
                'request' => $this->buildRequest(),
                'tableName' => 'tx_aim_page_prompt_fragment',
                'vanillaUid' => 1,
                'command' => 'new',
            ],
            GeneralUtility::makeInstance(TcaDatabaseRecord::class),
        );

        self::assertArrayNotHasKey(
            'addRecord',
            $formData['processedTca']['columns']['fragment']['config']['fieldControl'] ?? ['addRecord' => true],
            'The real FormEngine pipeline still offers "+ create new fragment" on an unsaved assignment - the provider is not wired in.',
        );
        self::assertArrayHasKey(
            'editPopup',
            $formData['processedTca']['columns']['fragment']['config']['fieldControl'] ?? [],
            'editPopup must stay available regardless - only addRecord is unsafe on an unsaved parent.',
        );
    }

    #[Test]
    public function theRealFormEnginePipelineKeepsAddRecordForAnAlreadySavedAssignment(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Siteroot',
        ]);
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 1, 'title' => 'Existing fragment', 'prompt' => 'Tone.', 'scope' => 'all']);
        $assignments = $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment');
        $assignments->insert('tx_aim_page_prompt_fragment', ['pid' => 1, 'parent_page' => 1, 'fragment' => (int)$fragments->lastInsertId()]);
        $assignmentUid = (int)$assignments->lastInsertId();

        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'pid' => 0,
            'username' => 'admin',
            'admin' => 1,
        ]);
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $formData = $this->get(FormDataCompiler::class)->compile(
            [
                'request' => $this->buildRequest(),
                'tableName' => 'tx_aim_page_prompt_fragment',
                'vanillaUid' => $assignmentUid,
                'command' => 'edit',
            ],
            GeneralUtility::makeInstance(TcaDatabaseRecord::class),
        );

        self::assertArrayHasKey('addRecord', $formData['processedTca']['columns']['fragment']['config']['fieldControl'] ?? []);
    }

    private function buildRequest(): ServerRequest
    {
        $url = 'https://typo3-testing.local/typo3/record/edit';
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3/record/edit',
            'REQUEST_METHOD' => 'GET',
        ];
        $request = new ServerRequest($url, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
