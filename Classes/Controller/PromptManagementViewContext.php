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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

final class PromptManagementViewContext
{
    /**
     * @param array<int, array{value: string, label: string}> $scopeOptions
     * @param array<string, string> $scopeLabelsByValue
     * @param array<string, array{active: bool, headline: string, description: string}> $viewBoxes
     */
    public function __construct(
        public readonly ModuleTemplate $view,
        public readonly ServerRequestInterface $request,
        public readonly BackendUserAuthentication $backendUser,
        public readonly ButtonBar $buttonBar,
        public readonly string $currentUrl,
        public readonly int $selectedPageId,
        public readonly LanguageService $languageService,
        public readonly array $scopeOptions,
        public readonly array $scopeLabelsByValue,
        public readonly array $viewBoxes,
        public readonly bool $hasFragmentReadAccess,
        public readonly bool $requestCarriedExplicitPageId,
    ) {}
}
