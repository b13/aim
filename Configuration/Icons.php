<?php

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tx-aim' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:aim/Resources/Public/Icons/Extension.svg',
    ],
    'tx-aim-configuration' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:aim/Resources/Public/Icons/ai_configuration.svg',
    ],
    'tx-aim-prompt-fragment' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:aim/Resources/Public/Icons/ai_prompt_fragment.svg',
    ],
];
