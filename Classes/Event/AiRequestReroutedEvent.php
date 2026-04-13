<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Event;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Request\AiRequestInterface;

/**
 * Dispatched when the capability validation middleware reroutes
 * a request to a different provider because the original provider
 * does not support the required capability.
 */
final class AiRequestReroutedEvent
{
    public function __construct(
        public readonly AiRequestInterface $request,
        public readonly ProviderConfiguration $originalConfiguration,
        public readonly ProviderConfiguration $newConfiguration,
        public readonly string $requiredCapability,
        public readonly string $reason,
    ) {}
}
