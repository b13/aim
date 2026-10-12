<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 project.
 * (c) 2025 B-Factor GmbH / Sudhaus7
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 * The TYPO3 project - inspiring people to share!
 *
 * @copyright 2025 B-Factor GmbH / Sudhaus7
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
