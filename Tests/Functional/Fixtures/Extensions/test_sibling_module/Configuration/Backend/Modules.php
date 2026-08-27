<?php

use TYPO3\CMS\Core\Information\Typo3Version;

return [
    'test_sibling_module' => [
        'parent' => (new Typo3Version())->getMajorVersion() >= 14 ? 'admin' : 'tools',
        'access' => 'user',
        'iconIdentifier' => 'module-system',
        'labels' => [
            'title' => 'Test sibling module',
        ],
        // Never actually invoked by the test: any dependency-free,
        // already-autoloaded controller action satisfies core's route
        // resolution requirement for a registered module.
        'routes' => [
            '_default' => [
                'target' => \B13\Aim\Controller\ProviderController::class . '::overviewAction',
            ],
        ],
    ],
];
