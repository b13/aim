<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Backend\FormDataProvider;

use B13\Aim\Backend\FormDataProvider\DisableAddRecordOnUnsavedAssignment;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Regression coverage for the TYPO3 core limitation this provider works
 * around: addRecord's AddController round-trip only writes the newly
 * created record's uid back into the field when
 * MathUtility::canBeInterpretedAsInteger($parentUid) is true, silently
 * failing to link anything (while still force-navigating away and
 * discarding the unsaved parent) for a "NEW..." placeholder uid.
 */
final class DisableAddRecordOnUnsavedAssignmentTest extends UnitTestCase
{
    private const FRAGMENT_FIELD_TCA = [
        'config' => [
            'fieldControl' => [
                'addRecord' => ['disabled' => false],
                'editPopup' => ['disabled' => false],
            ],
        ],
    ];

    #[Test]
    public function removesAddRecordForABrandNewUnsavedAssignment(): void
    {
        $provider = new DisableAddRecordOnUnsavedAssignment();
        $result = $provider->addData([
            'tableName' => 'tx_aim_page_prompt_fragment',
            'databaseRow' => ['uid' => 'NEW68a1b2c3d4e5f'],
            'processedTca' => ['columns' => ['fragment' => self::FRAGMENT_FIELD_TCA]],
        ]);

        self::assertArrayNotHasKey('addRecord', $result['processedTca']['columns']['fragment']['config']['fieldControl']);
    }

    #[Test]
    public function keepsEditPopupForABrandNewUnsavedAssignment(): void
    {
        // Only addRecord round-trips through the broken AddController path;
        // editPopup just opens the already-selected fragment and is
        // unaffected by the parent's own saved state.
        $provider = new DisableAddRecordOnUnsavedAssignment();
        $result = $provider->addData([
            'tableName' => 'tx_aim_page_prompt_fragment',
            'databaseRow' => ['uid' => 'NEW68a1b2c3d4e5f'],
            'processedTca' => ['columns' => ['fragment' => self::FRAGMENT_FIELD_TCA]],
        ]);

        self::assertArrayHasKey('editPopup', $result['processedTca']['columns']['fragment']['config']['fieldControl']);
    }

    #[Test]
    public function keepsAddRecordForAnAlreadySavedAssignment(): void
    {
        $provider = new DisableAddRecordOnUnsavedAssignment();
        $input = [
            'tableName' => 'tx_aim_page_prompt_fragment',
            'databaseRow' => ['uid' => 2],
            'processedTca' => ['columns' => ['fragment' => self::FRAGMENT_FIELD_TCA]],
        ];

        $result = $provider->addData($input);

        self::assertSame($input, $result);
    }

    #[Test]
    public function ignoresOtherTables(): void
    {
        $provider = new DisableAddRecordOnUnsavedAssignment();
        $input = [
            'tableName' => 'pages',
            'databaseRow' => ['uid' => 'NEWfoo'],
        ];

        $result = $provider->addData($input);

        self::assertSame($input, $result);
    }
}
