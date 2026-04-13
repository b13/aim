<?php

use B13\Aim\Controller\ProviderController;
use B13\Aim\Controller\RequestLogController;

return [
    'aim_available_providers' => [
        'path' => '/aim/available-providers',
        'target' => ProviderController::class . '::availableProvidersAction',
        'methods' => ['GET'],
    ],
    'aim_toggle_model' => [
        'path' => '/aim/toggle-model',
        'target' => ProviderController::class . '::toggleModelAction',
        'methods' => ['POST'],
    ],
    'aim_verify_provider' => [
        'path' => '/aim/verify-provider',
        'target' => ProviderController::class . '::verifyProviderAction',
        'methods' => ['POST'],
    ],
    'aim_request_log_poll' => [
        'path' => '/aim/request-log/poll',
        'target' => RequestLogController::class . '::pollAction',
        'methods' => ['GET'],
    ],
];
