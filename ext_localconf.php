<?php

declare(strict_types=1);

use B13\Aim\Hooks\DefaultProviderHook;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][DefaultProviderHook::class] = DefaultProviderHook::class;

// Register AI capability permissions for backend user groups
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['aim'] = [
    'header' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:permissions.header',
    'items' => [
        'capability_text' => [
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:permissions.capability.text',
            'actions-bolt',
        ],
        'capability_vision' => [
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:permissions.capability.vision',
            'actions-image',
        ],
        'capability_translation' => [
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:permissions.capability.translation',
            'actions-localize',
        ],
        'capability_conversation' => [
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:permissions.capability.conversation',
            'actions-chat',
        ],
        'capability_embedding' => [
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:permissions.capability.embedding',
            'actions-database',
        ],
        'capability_toolcalling' => [
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:permissions.capability.toolcalling',
            'actions-cog',
        ],
    ],
];
