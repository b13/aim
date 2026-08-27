<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_prompt_fragment.title',
        'label' => 'title',
        // A reusable library entry, not a page-owned child record anymore
        // (see tx_aim_page_prompt_fragment for the per-page assignment) - it
        // should usually live at pid=0, but an editor without access to
        // pid=0 needs to be able to place one in a sysfolder instead, hence
        // rootLevel=-1 rather than the fixed root-only 1. Left visible in
        // the List module (not hideTable) so the library is browsable/
        // manageable on its own, ahead of a dedicated module UI.
        'rootLevel' => -1,
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
                scope, --linebreak--,
                hidden',
        ],
    ],
    'columns' => [
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
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.disable',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
    ],
];
