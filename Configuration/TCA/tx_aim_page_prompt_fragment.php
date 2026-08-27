<?php

use B13\Aim\Backend\Form\PagePromptFragmentLabelUserFunc;
use B13\Aim\Backend\Form\Wizard\PromptFragmentSuggestReceiver;

return [
    'ctrl' => [
        'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_page_prompt_fragment.title',
        'label' => 'fragment',
        'label_userFunc' => PagePromptFragmentLabelUserFunc::class . '->getLabel',
        'sortby' => 'sorting',
        'hideTable' => true,
        'crdate' => 'crdate',
        'tstamp' => 'tstamp',
        'delete' => 'deleted',
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
            'showitem' => 'fragment, inherit_to_subpages',
        ],
    ],
    'columns' => [
        'fragment' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_page_prompt_fragment.columns.fragment.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_page_prompt_fragment.columns.fragment.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_aim_prompt_fragment',
                'relationship' => 'manyToOne',
                'size' => 1,
                'minitems' => 1,
                'maxitems' => 1,
                'suggestOptions' => [
                    'default' => [
                        'additionalSearchFields' => 'prompt,examples',
                        'addWhere' => '###CURRENT_PID###',
                        'receiverClass' => PromptFragmentSuggestReceiver::class,
                    ],
                ],
                'fieldControl' => [
                    'editPopup' => [
                        'disabled' => false,
                    ],
                    'addRecord' => [
                        'disabled' => false,
                        'options' => [
                            'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_page_prompt_fragment.columns.fragment.addRecord',
                            'pid' => '###SITEROOT###',
                        ],
                    ],
                ],
            ],
        ],
        'inherit_to_subpages' => [
            'label' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_page_prompt_fragment.columns.inherit_to_subpages.label',
            'description' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_page_prompt_fragment.columns.inherit_to_subpages.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'parent_page' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        // Internal marker, used by aim:calibrateVoice command, or a consumer extension's equivalent.
        'auto_generated' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        // Flag for which caller set auto_generated marker.
        'auto_generated_source' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
];
