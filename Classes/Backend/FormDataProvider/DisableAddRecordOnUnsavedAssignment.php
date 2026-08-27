<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Hides the `fragment` field's "+ create new fragment" button
 * (fieldControl.addRecord) while the tx_aim_page_prompt_fragment
 * assignment itself is still a brand-new, not-yet-saved IRRE child (a
 * "NEW..." placeholder uid, not a real integer).
 *
 * @todo Remove this class (and its TCA wiring) once TYPO3 core ships the
 *       real fix for this: a new "_savedokaddrecord" FormAction that saves
 *       the unsaved parent first, then continues into the addRecord wizard
 *       with the real, persisted uid, instead of silently discarding it.
 *       See https://forge.typo3.org/issues/110526. Fixed on core's `main`
 *       branch (future v15); not yet merged or backported. Keep this
 *       workaround until that fix is available in aim's own minimum
 *       supported core version, at least v14.
 */
final class DisableAddRecordOnUnsavedAssignment implements FormDataProviderInterface
{
    public function addData(array $result): array
    {
        if (($result['tableName'] ?? '') !== 'tx_aim_page_prompt_fragment') {
            return $result;
        }

        $uid = $result['databaseRow']['uid'] ?? null;
        if (MathUtility::canBeInterpretedAsInteger($uid)) {
            return $result;
        }

        unset($result['processedTca']['columns']['fragment']['config']['fieldControl']['addRecord']);

        return $result;
    }
}
