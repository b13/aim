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

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Counts AI requests per backend user, per minute, keyed by minute bucket.
 *
 * Deliberately not counted from tx_aim_request_log: privacy_level = none
 * inserts no rows, which would leave the limiter permanently at zero.
 */
final class RateLimitCounter
{
    private const CACHE_IDENTIFIER = 'aim_ratelimit';
    private const BUCKET_SECONDS = 60;

    private static bool $cacheFailureLogged = false;

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Records one request and returns this user's count for the current
     * minute, including it. A rejected request still counts.
     */
    public function record(int $userId): int
    {
        $cache = $this->cache();
        if ($cache === null) {
            return 0;
        }

        try {
            $identifier = $this->bucketIdentifier($userId);
            $count = (int)($cache->get($identifier) ?: 0) + 1;
            $cache->set($identifier, $count, [], self::BUCKET_SECONDS * 2);
        } catch (\Throwable $e) {
            if (!self::$cacheFailureLogged) {
                self::$cacheFailureLogged = true;
                $this->logger->warning(
                    'The "aim_ratelimit" cache is unusable, the rate limit is not being enforced: ' . $e->getMessage()
                );
            }

            return 0;
        }

        return $count;
    }

    private function bucketIdentifier(int $userId): string
    {
        return 'u' . $userId . '-' . (int)floor(time() / self::BUCKET_SECONDS);
    }

    /**
     * Returns null rather than throwing: a missing cache must not block a request.
     */
    private function cache(): ?FrontendInterface
    {
        try {
            $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
            $cache->has('probe');
            return $cache;
        } catch (\Throwable) {
            return null;
        }
    }
}
