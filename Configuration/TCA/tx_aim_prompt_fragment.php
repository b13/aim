<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.title',
        'label' => 'title',
        'sortby' => 'sorting',
        'hideTable' => true,
        'crdate' => 'crdate',
        'tstamp' => 'tstamp',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'versioningWS' => true,
        'versioningWS_alwaysAllowLiveEdit' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'typeicon_classes' => [
            'default' => 'tx-aim-prompt-fragment',
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                title, prompt, examples, --linebreak--,
                scope, inherit_to_subpages, --linebreak--,
                hidden',
        ],
    ],
    'columns' => [
        'parent_page' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        // Internal marker for aim:calibrateVoice (see
        // PagePromptFragmentRepository::upsertAutoDetectedFragment()), not
        // a form field; an editor's own hand-authored fragments are
        // unaffected either way and never carry this flag.
        'auto_generated' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.title.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.title.description',
            'config' => [
                'type' => 'input',
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'prompt' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.prompt.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.prompt.description',
            'config' => [
                'type' => 'text',
                'rows' => 5,
                'cols' => 40,
                'required' => true,
                'max' => 4000,
            ],
        ],
        'examples' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.examples.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.examples.description',
            'config' => [
                'type' => 'text',
                'rows' => 8,
                'cols' => 40,
                'max' => 6000,
            ],
        ],
        'scope' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'items' => [
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.text', 'value' => 'text'],
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.vision', 'value' => 'vision'],
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.translation', 'value' => 'translation'],
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.conversation', 'value' => 'conversation'],
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.toolCalling', 'value' => 'toolCalling'],
                    ['label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.scope.imageGeneration', 'value' => 'imageGeneration'],
                ],
                'default' => 'text,vision,translation,conversation,toolCalling,imageGeneration',
            ],
        ],
        'inherit_to_subpages' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.inherit_to_subpages.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.columns.inherit_to_subpages.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.disable',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
    ],
];
