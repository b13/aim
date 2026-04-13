<?php

return [
    'aim' => [
        'parent' => 'admin',
        'position' => ['before' => '*'],
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        'showSubmoduleOverview' => true,
        'labels' => [
            'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.title',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.description',
            'shortDescription' => 'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:aim.shortDescription',
        ],
        'iconIdentifier' => 'tx-aim',
    ],
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
                'target' => \B13\Aim\Controller\ProviderController::class . '::overviewAction',
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
                'target' => \B13\Aim\Controller\RequestLogController::class . '::logAction',
            ],
        ],
    ],
];
