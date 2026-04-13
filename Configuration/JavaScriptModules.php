<?php

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'tags' => [
        'backend.module',
    ],
    'imports' => [
        '@b13/aim/' => 'EXT:aim/Resources/Public/JavaScript/',
    ],
];
