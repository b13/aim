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
use B13\Aim\Response\TextResponse;

/**
 * Dispatched after a provider has returned a response.
 *
 * Listeners can inspect the response, the original request, provider manifest,
 * and configuration. Dispatched by the EventDispatchMiddleware (priority -900).
 */
final class AfterAiResponseEvent
{
    public function __construct(
        public readonly TextResponse $response,
        public readonly AiRequestInterface $request,
        public readonly AiProviderManifest $provider,
        public readonly ProviderConfiguration $configuration,
    ) {}
}
