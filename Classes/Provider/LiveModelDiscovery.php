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

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
    /**
     * A reachable endpoint is cached for long enough to survive a form session;
     * an unreachable or empty one only briefly, so fixing an endpoint shows up
     * quickly instead of being masked for the full period.
     */
    private const CACHE_IDENTIFIER = 'aim_models';
    private const LIFETIME_FOUND = 900;
    private const LIFETIME_EMPTY = 60;

    /**
     * A host is free to advertise thousands of models. They become TCA select
     * items and one cache entry, so the list is bounded.
     */
    private const MAX_MODELS = 100;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * Return the model names available on the given endpoint, or [] on any failure.
     * $credential is sent as a bearer token when the host wants one.
     *
     * @return list<string>
     */
    public function fetchModelNames(string $endpoint, string $credential = ''): array
    {
        if (!$this->isHttpEndpoint($endpoint)) {
            return [];
        }

        // Normalised before hashing, or the same request under a trailing
        // slash would occupy a second entry that can go stale independently.
        $baseUrl = rtrim($endpoint, '/');
        $cache = $this->cache();
        $cacheIdentifier = $this->cacheIdentifier($baseUrl, $credential);
        $cached = $this->readCache($cache, $cacheIdentifier);
        if ($cached !== null) {
            return $cached;
        }

        $options = ['timeout' => 3, 'connect_timeout' => 2, 'http_errors' => false];
        // A row the split migration has not reached hands the same value as
        // both, and the endpoint is no use as a bearer token.
        if ($credential !== '' && $credential !== $endpoint) {
            $options['headers'] = ['Authorization' => 'Bearer ' . $credential];
        }

        try {
            $response = $this->requestFactory->request($baseUrl . '/v1/models', 'GET', $options);
            if ($response->getStatusCode() !== 200) {
                // Cached like an empty result: an unreachable or refusing host
                // is the expensive case, since it costs the full timeout on
                // every form render until someone fixes it.
                $this->writeCache($cache, $cacheIdentifier, []);

                return [];
            }
            $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                $this->writeCache($cache, $cacheIdentifier, []);

                return [];
            }
        } catch (\Throwable) {
            $this->writeCache($cache, $cacheIdentifier, []);

            return [];
        }

        $names = [];
        $entries = $payload['data'] ?? [];
        if (is_iterable($entries)) {
            foreach ($entries as $entry) {
                $name = is_array($entry) ? (string)($entry['id'] ?? '') : '';
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
                if (count($names) >= self::MAX_MODELS) {
                    break;
                }
            }
        }

        $this->writeCache($cache, $cacheIdentifier, $names);

        return $names;
    }

    /**
     * Keyed on the endpoint and the credential, because two configurations can
     * point at the same host with different tokens and see different models.
     *
     * Keyed through an HMAC rather than a bare hash: the credential is encrypted
     * at rest on purpose, and the endpoint sits in plaintext beside it.
     */
    private function cacheIdentifier(string $baseUrl, string $credential): string
    {
        $secret = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        $material = $baseUrl . "\0" . $credential;

        return 'm' . ($secret === ''
            ? hash('sha256', $material)
            : hash_hmac('sha256', $material, $secret));
    }

    /**
     * Returns null rather than throwing: a missing cache must not stop discovery.
     */
    private function cache(): ?FrontendInterface
    {
        try {
            $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
            // Touch it here, so a missing table surfaces in this try rather
            // than at the first get() further down.
            $cache->has('probe');
            return $cache;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>|null
     */
    private function readCache(?FrontendInterface $cache, string $identifier): ?array
    {
        if ($cache === null) {
            return null;
        }

        try {
            $cached = $cache->get($identifier);
        } catch (\Throwable) {
            return null;
        }

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param list<string> $names
     */
    private function writeCache(?FrontendInterface $cache, string $identifier, array $names): void
    {
        if ($cache === null) {
            return;
        }

        try {
            $cache->set(
                $identifier,
                $names,
                [],
                $names === [] ? self::LIFETIME_EMPTY : self::LIFETIME_FOUND,
            );
        } catch (\Throwable) {
            // Discovery still works, it is just not cached.
        }
    }

    public function isHttpEndpoint(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }
}
