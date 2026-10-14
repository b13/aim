<?php

declare(strict_types=1);

return [
    'frontend' => [
        'b13/aim-test/internal-request-context' => [
            'target' => \B13\Aim\Tests\Functional\Fixtures\Frontend\InternalRequestContextMiddleware::class,
            'after' => [
                'typo3/cms-frontend/backend-user-authentication',
            ],
            'before' => [
                'typo3/json-response/frontend-user-authentication',
                'typo3/json-response/backend-user-authentication',
            ],
        ],
    ],
];
