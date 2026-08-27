<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Controller;

/**
 * The sub-actions, PromptManagementController::overviewAction() renders,
 * switched via box UI in PromptManagementViewSwitch.html and persisted
 * per backend user through the module's own 'view' moduleData property.
 *
 *  - Pages:   lists pages that have at least one prompt fragment.
 *  - Library: the reusable fragment pool itself.
 */
enum PromptManagementView: string
{
    case Pages = 'pages';
    case Library = 'library';
}
