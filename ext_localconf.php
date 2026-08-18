<?php

declare(strict_types=1);

use B13\Aim\Backend\FormDataProvider\HideApiKey;
use B13\Aim\Hooks\DefaultProviderHook;
use B13\Aim\Hooks\EncryptApiKey;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][DefaultProviderHook::class] = DefaultProviderHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][EncryptApiKey::class] = EncryptApiKey::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][HideApiKey::class] = [
    'depends' => [\TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow::class],
];

// Persists PromptFragmentRegistry's package filesystem scan across requests
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caches']['aim_prompt_fragments'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caches']['aim_prompt_fragments'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'groups' => ['system'],
    ];
}

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
