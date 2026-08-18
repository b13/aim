<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads the "aim-console" stylesheet (base.css) so it can be embedded
 * directly into a Fluid fragment's own markup, rather than loaded via
 * includeCssFiles. Every fragment fetched through Modal.types.ajax and
 * inserted via lit's unsafeHTML() ends up in a <typo3-backend-modal>
 * element that lit itself manages. A <style> tag baked into the exact
 * HTML string lit parses survives; a JS-side DOM mutation applied to that
 * same element after the fact does not, since lit's own reconciliation can
 * silently discard it on a later re-render.
 */
final class ConsoleStylesheetProvider
{
    public function getCss(): string
    {
        $cssPath = GeneralUtility::getFileAbsFileName('EXT:aim/Resources/Public/Css/base.css');
        return is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
    }
}
