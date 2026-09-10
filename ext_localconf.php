<?php

declare(strict_types=1);

use B13\Aim\Backend\FormDataProvider\DisableAddRecordOnUnsavedAssignment;
use B13\Aim\Backend\FormDataProvider\HideApiKey;
use B13\Aim\Hooks\DefaultProviderHook;
use B13\Aim\Hooks\EncryptApiKey;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][DefaultProviderHook::class] = DefaultProviderHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][EncryptApiKey::class] = EncryptApiKey::class;

// Must run before TcaSelectItems: the model field's itemsProcFunc reads api_key
// to authenticate live model discovery, so if this provider ran later it would
// see the stored ciphertext and send that to the endpoint as a bearer token.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][HideApiKey::class] = [
    'depends' => [\TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow::class],
    'before' => [\TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectItems::class],
];

// Button next to the API key field that marks the stored credential for deletion.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1757000000] = [
    'nodeName' => 'aimClearApiKey',
    'priority' => 40,
    'class' => \B13\Aim\Backend\Form\FieldControl\ClearApiKey::class,
];

// @todo Removed once fixed in core, see https://forge.typo3.org/issues/110526.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][DisableAddRecordOnUnsavedAssignment::class] = [
    'depends' => [
        \TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUniqueUidNewRow::class,
        \TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessShowitem::class,
    ],
];

// Persists PromptFragmentRegistry's package filesystem scan across requests.
// SYS/caching/cacheConfigurations is the key core reads; SYS/caches is not.
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['aim_prompt_fragments'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['aim_prompt_fragments'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'groups' => ['system'],
    ];
}

// Model lists fetched live from a self-hosted provider. Every render of a
// provider configuration form would otherwise re-query the endpoint, and an
// unreachable one costs the full connect timeout each time.
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['aim_models'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['aim_models'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'groups' => ['system'],
    ];
}

// Per-user request counters for the governance rate limiter. Deliberately not
// the request log: privacy_level = none writes no rows at all, which silently
// disabled the limiter for exactly the configurations most likely to want one.
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['aim_ratelimit'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['aim_ratelimit'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'groups' => ['system'],
        'options' => [
            'defaultLifetime' => 120,
        ],
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

// Only for TYPO3 12.4's compatibility
$GLOBALS['TYPO3_CONF_VARS']['FE']['addRootLineFields'] = trim(
    ($GLOBALS['TYPO3_CONF_VARS']['FE']['addRootLineFields'] ?? '') . ',tx_aim_disable_inherited_fragments',
    ','
);
