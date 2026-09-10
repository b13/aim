<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Governance;

use B13\Aim\Domain\Model\ProviderConfiguration;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether the current backend user may use a provider configuration.
 *
 * An empty be_groups means no restriction. Admins pass, and so does a request
 * with no authenticated backend user. Note that in CLI core sets
 * $GLOBALS['BE_USER'] to an unauthenticated CommandLineUserAuthentication for
 * every command, so the object is present while `user` is null and
 * userGroupsUID is empty: testing for null alone would make every restricted
 * configuration unusable from a command.
 */
final class ConfigurationAccess
{
    public function isAccessibleByCurrentUser(ProviderConfiguration $configuration): bool
    {
        if ($configuration->beGroups === '') {
            return true;
        }
        $user = $this->getBackendUser();
        if ($user === null || (int)($user->user['uid'] ?? 0) <= 0 || $user->isAdmin()) {
            return true;
        }
        $allowedGroupIds = array_map('intval', explode(',', $configuration->beGroups));
        $userGroupIds = array_map('intval', $user->userGroupsUID ?? []);

        return array_intersect($allowedGroupIds, $userGroupIds) !== [];
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
