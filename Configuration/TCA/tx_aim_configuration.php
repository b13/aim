<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.title',
        'label' => 'title',
        'descriptionColumn' => 'description',
        'crdate' => 'crdate',
        'tstamp' => 'tstamp',
        'adminOnly' => true,
        'hideTable' => true,
        'rootLevel' => 1,
        'groupName' => 'system',
        'default_sortby' => 'title',
        'type' => 'ai_provider',
        'typeicon_column' => 'ai_provider',
        'typeicon_classes' => [
            'default' => 'tx-aim',
        ],
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'disabled',
        ],
        'versioningWS_alwaysAllowLiveEdit' => true,
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                --palette--;;config,
                --palette--;;tokenCosts,
                --palette--;;cost,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                --palette--;;access,
                --palette--;;governance',
        ],
    ],
    'palettes' => [
        'config' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.palette.config.label',
            'showitem' => 'ai_provider, --linebreak--, title, description, --linebreak--, api_key, model, --linebreak--, default',
        ],
        'tokenCosts' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.palette.tokenCosts.label',
            'showitem' => 'max_tokens, --linebreak--, input_token_cost, output_token_cost',
        ],
        'cost' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.palette.cost.label',
            'showitem' => 'total_cost, cost_currency',
        ],
        'governance' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.palette.governance.label',
            'showitem' => 'be_groups, --linebreak--, privacy_level, --linebreak--, rerouting_allowed, auto_model_switch',
        ],
        'access' => [
            'label' => 'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.palettes.access',
            'showitem' => 'disabled',
        ],
    ],
    'columns' => [
        'ai_provider' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.ai_provider.label',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'required' => true,
                'items' => [
                    ['label' => '', 'value' => ''],
                ],
                'itemsProcFunc' => \B13\Aim\Tca\ItemsProcFunc\AiProvidersItemsProcFunc::class . '->getAiProviders',
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.title.label',
            'config' => [
                'type' => 'input',
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.description.label',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 30,
            ],
        ],
        'default' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.default.label',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'api_key' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.label',
            'config' => [
                'type' => 'input',
                'required' => true,
            ],
        ],
        'model' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.model.label',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'required' => true,
                'items' => [
                    ['label' => '', 'value' => ''],
                ],
                'itemsProcFunc' => \B13\Aim\Tca\ItemsProcFunc\AiProvidersItemsProcFunc::class . '->getAiProviderModels',
            ],
        ],
        'max_tokens' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.max_tokens.label',
            'config' => [
                'type' => 'number',
                'default' => 150,
            ],
        ],
        'input_token_cost' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.input_token_cost.label',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'default' => 0,
            ],
        ],
        'output_token_cost' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.output_token_cost.label',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'default' => 0,
            ],
        ],
        'total_cost' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.total_cost.label',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'readOnly' => true,
            ],
        ],
        'cost_currency' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.cost_currency.label',
            'config' => [
                'type' => 'input',
                'default' => 'USD',
                'size' => 5,
            ],
        ],
        'be_groups' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.be_groups.label',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'be_groups',
                'foreign_table_where' => 'ORDER BY be_groups.title',
                'size' => 5,
                'maxitems' => 20,
            ],
        ],
        'privacy_level' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.privacy_level.label',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.privacy_level.standard', 'value' => 'standard'],
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.privacy_level.reduced', 'value' => 'reduced'],
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.privacy_level.none', 'value' => 'none'],
                ],
                'default' => 'standard',
            ],
        ],
        'rerouting_allowed' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.rerouting_allowed.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.rerouting_allowed.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'auto_model_switch' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.auto_model_switch.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.auto_model_switch.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'disabled' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.enabled',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true,
                    ],
                ],
            ],
        ],
    ],
];
