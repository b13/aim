<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend\Form\FieldControl;

use B13\Aim\Hooks\EncryptApiKey;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * A button next to the API key field that marks the stored credential for
 * deletion on the next save.
 *
 * A stored key is never shown again, so an empty field is the normal state when
 * editing and "empty means keep it" is the only sane reading of a blank
 * submission. That leaves no way to express "remove it", which matters: a
 * configuration repointed from a hosted provider to a local one keeps sending
 * the old cloud credential to the new host.
 */
final class ClearApiKey extends AbstractNode
{
    public function render(): array
    {
        $id = StringUtility::getUniqueId('t3js-aim-clear-api-key-');
        $languageService = $this->getLanguageService();
        $label = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.clear');
        $labelArmed = $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.clear.armed');

        return [
            'iconIdentifier' => 'actions-delete',
            'title' => 'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.clear',
            'linkAttributes' => [
                'id' => $id,
                // FieldControl only puts the title on the icon, so the button
                // itself has neither a tooltip nor an accessible name without
                // these. Both are swapped by JavaScript.
                'title' => $label,
                'aria-label' => $label,
                'role' => 'button',
                'aria-pressed' => 'false',
                'data-item-name' => (string)$this->data['parameterArray']['itemFormElName'],
                'data-marker' => EncryptApiKey::CLEAR_MARKER,
                'data-icon-armed' => 'actions-undo',
                'data-label-armed' => $labelArmed,
                'data-label-idle' => $label,
                'data-label-pending' => $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.clear.pending'),
                'data-placeholder-pending' => $languageService->sL('LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_configuration.columns.api_key.clear.placeholder'),
            ],
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create('@b13/aim/field-control/clear-api-key.js')->instance($id),
            ],
        ];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
