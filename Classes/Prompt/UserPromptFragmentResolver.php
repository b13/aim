<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Prompt;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Resolves prompt fragments assigned via User/Group TSconfig
 * (aim.promptFragments.<key>.prompt/.scope, see TsConfigFragmentParser),
 * the same dot-path syntax as Page TSconfig, but on the person/role axis
 * rather than the page-tree axis. Lets an admin assign a fragment to a
 * specific BE user or group regardless of which page they work on.
 *
 * Applied unconditionally (like PromptFragmentRegistry), independent of
 * pageId: a page context isn't meaningful for "who is making this
 * request". No-op when no BE_USER exists (CLI/frontend), same convention
 * as AccessControlMiddleware's getBackendUser().
 */
class UserPromptFragmentResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<string>
     */
    public function getFragments(PromptFragmentScope $scope): array
    {
        if (!($user = $this->getBackendUserAuthentication()) instanceof BackendUserAuthentication) {
            return [];
        }

        $fragments = TsConfigFragmentParser::parse($user->getTSConfig()['aim.']['promptFragments.'] ?? [], $this->logger);
        return $scope->filterPrompts($fragments);
    }

    private function getBackendUserAuthentication(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
