<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Provider;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Discovers models live from an OpenAI-compatible HTTP endpoint.
 *
 * Bridges with dynamic catalogs (Ollama, LM Studio, etc.) can't be enumerated
 * at container-compile time. Models live on the running server and only the
 * admin's configuration record knows the endpoint URL. This service hits the
 * OpenAI-compatible /v1/models endpoint, which is exposed by both Ollama
 * (compat layer, on by default) and LM Studio (its only API).
 *
 * Failure modes (unreachable, non-JSON, unexpected shape, non-200 status)
 * return an empty array instead of throwing; the caller decides whether to
 * surface the gap to the admin.
 */
final class LiveModelDiscovery
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * Return the model names available on the given endpoint, or [] on any failure.
     *
     * @return list<string>
     */
    public function fetchModelNames(string $endpoint): array
    {
        if (!$this->isHttpEndpoint($endpoint)) {
            return [];
        }

        try {
            $response = $this->requestFactory->request(
                rtrim($endpoint, '/') . '/v1/models',
                'GET',
                ['timeout' => 3, 'connect_timeout' => 2, 'http_errors' => false],
            );
            if ($response->getStatusCode() !== 200) {
                return [];
            }
            $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        $names = [];
        foreach ($payload['data'] ?? [] as $entry) {
            $name = (string)($entry['id'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    public function isHttpEndpoint(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }
}
