<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Capability;

use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Response\ToolCallingResponse;

interface ToolCallingCapableInterface extends AiCapabilityInterface
{
    public function processToolCallingRequest(ToolCallingRequest $request): ToolCallingResponse;
}
