<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\AiLabel\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Stand-in for the real B13\AiLabel\Service\AiLabelApi (EXT:ai_label), which
 * AiLabelMiddlewareTest cannot depend on directly: ai_label is a sibling
 * extension, not a composer dependency of aim, so it's never actually
 * installed in aim's own isolated test run. Declaring a class under its
 * exact real namespace/name here makes AiLabelMiddleware's class_exists()
 * guard genuinely pass, exercising the "ai_label is installed" branch -
 * the real class is never loaded in the same process, so there's no
 * redeclaration conflict.
 *
 * Matches the real class's public method signatures (aiCreated/aiModified)
 * so it's a faithful stand-in, but only records calls instead of running a
 * real DataHandler write.
 */
class AiLabelApi
{
    /** @var list<array{method: string, table: string, uid: int}> */
    public array $calls = [];

    public function aiCreated(string $table, int $uid, ?BackendUserAuthentication $user = null): void
    {
        $this->calls[] = ['method' => 'aiCreated', 'table' => $table, 'uid' => $uid];
    }

    public function aiModified(string $table, int $uid, ?BackendUserAuthentication $user = null): void
    {
        $this->calls[] = ['method' => 'aiModified', 'table' => $table, 'uid' => $uid];
    }
}
