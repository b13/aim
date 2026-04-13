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

use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Request\AiRequestInterface;

/**
 * Dispatched before an AI request is sent to a provider.
 *
 * Listeners can inspect the request, provider manifest, and configuration.
 * Dispatched by the EventDispatchMiddleware (priority -900).
 */
final class BeforeAiRequestEvent
{
    public function __construct(
        private readonly AiRequestInterface $request,
        public readonly AiProviderManifest $provider,
        public readonly ProviderConfiguration $configuration,
    ) {}

    public function getRequest(): AiRequestInterface
    {
        return $this->request;
    }
}
