<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

(static function (): void {
    ExtensionManagementUtility::addTCAcolumns('pages', [
        'tx_aim_disable_inherited_fragments' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:pages.tx_aim_disable_inherited_fragments.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:pages.tx_aim_disable_inherited_fragments.description',
            'exclude' => true,
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'tx_aim_prompt_fragments' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:pages.tx_aim_prompt_fragments.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:pages.tx_aim_prompt_fragments.description',
            'exclude' => true,
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_aim_page_prompt_fragment',
                'foreign_field' => 'parent_page',
                'foreign_sortby' => 'sorting',
                'appearance' => [
                    'expandSingle' => true,
                    'useSortable' => true,
                    'showSynchronizationLink' => true,
                    'showAllLocalizationLink' => true,
                    'showPossibleLocalizationRecords' => true,
                ],
            ],
        ],
    ]);

    // Not restricted by doktype: tone-of-voice should apply regardless of
    // page type, and PagePromptResolver walks the rootline across all of them.
    ExtensionManagementUtility::addToAllTCAtypes(
        'pages',
        '--div--;LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:pages.tab_ai,'
        . ' tx_aim_disable_inherited_fragments, tx_aim_prompt_fragments',
    );
})();
