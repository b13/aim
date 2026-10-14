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
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientPlatformAdapterFactoryEvent
{
    private ?HttpClientInterface $httpClient = null;
    public function __construct(
        public readonly ProviderConfiguration $configuration,
    ) {}

    public function getHttpClient(): ?HttpClientInterface
    {
        return $this->httpClient;
    }

    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }
}
