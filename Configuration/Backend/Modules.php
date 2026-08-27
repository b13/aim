<?php

use B13\Aim\Controller\PromptManagementController;
use B13\Aim\Controller\PromptManagementView;
use B13\Aim\Controller\ProviderController;
use B13\Aim\Controller\RequestLogController;
use TYPO3\CMS\Core\Information\Typo3Version;

$majorVersion = (new Typo3Version())->getMajorVersion();
$parent = $majorVersion >= 14 ? 'admin' : 'tools';

$aim = [
    'parent' => $parent,
    'position' => ['before' => '*'],
    'labels' => [
        'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.title',
        'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.description',
        'shortDescription' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.shortDescription',
    ],
    'iconIdentifier' => 'tx-aim',
];

if ($majorVersion >= 14) {
    $aim['appearance']['dependsOnSubmodules'] = true;
    $aim['showSubmoduleOverview'] = true;
}

return [
    'aim' => $aim,
    'aim_providers' => [
        'parent' => 'aim',
        'access' => 'admin',
        'position' => ['before' => '*'],
        'path' => '/module/admin/aim/providers',
        'iconIdentifier' => 'tx-aim',
        'labels' => [
            'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.providers.title',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.providers.description',
            'shortDescription' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.providers.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => ProviderController::class . '::overviewAction',
            ],
        ],
    ],
    'aim_request_log' => [
        'parent' => 'aim',
        'access' => 'admin',
        'position' => ['after' => 'aim_providers'],
        'path' => '/module/admin/aim/request-log',
        'iconIdentifier' => 'tx-aim',
        'labels' => [
            'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.requestLog.title',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.requestLog.description',
            'shortDescription' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.requestLog.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => RequestLogController::class . '::logAction',
            ],
            'show' => [
                'target' => RequestLogController::class . '::showAction',
            ],
            'showContextual' => [
                'target' => RequestLogController::class . '::showContextualAction',
            ],
        ],
    ],
    'aim_prompt_management' => [
        'parent' => 'aim',
        'access' => 'user',
        'position' => ['after' => 'aim_request_log'],
        'path' => '/module/admin/aim/prompt-management',
        'iconIdentifier' => 'tx-aim',
        'navigationComponent' => $majorVersion >= 13
            ? '@typo3/backend/tree/page-tree-element'
            : '@typo3/backend/page-tree/page-tree-element',
        'labels' => [
            'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.promptManagement.title',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.promptManagement.description',
            'shortDescription' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.promptManagement.shortDescription',
        ],
        'routes' => [
            '_default' => [
                'target' => PromptManagementController::class . '::overviewAction',
            ],
        ],
        'moduleData' => [
            'view' => PromptManagementView::Pages->value,
        ],
    ],
];
