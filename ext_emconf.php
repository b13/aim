<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AiM',
    'description' => 'AiM - Intelligent AI proxy for TYPO3',
    'category' => 'module',
    'author' => 'Oli Bartsch',
    'author_email' => 'oliver.bartsch@b13.com',
    'author_company' => 'b13 GmbH',
    'state' => 'alpha',
    'version' => '0.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'B13\\Aim\\' => 'Classes/',
        ],
    ],
];
