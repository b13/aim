<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Widgets\Provider;

use B13\Aim\Domain\Repository\RequestLogDemand;
use B13\Aim\Domain\Repository\RequestLogRepository;

/**
 * Provides the most recent AI requests for the dashboard list widget.
 */
class RecentRequestsDataProvider
{
    public function __construct(
        private readonly RequestLogRepository $logRepository,
        private readonly int $limit = 10,
    ) {}

    public function getItems(): array
    {
        $demand = new RequestLogDemand(page: 1);
        $entries = $this->logRepository->findByDemand($demand);
        $items = [];
        foreach (array_slice($entries, 0, $this->limit) as $entry) {
            $items[] = [
                'crdate' => date(
                    ($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'Y-m-d') . ' ' . ($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i'),
                    (int)($entry['crdate'] ?? 0)
                ),
                'extension_key' => $entry['extension_key'] ?? '',
                'provider' => $entry['provider_identifier'] ?? '',
                'model' => $entry['model_used'] ?: ($entry['model_requested'] ?? ''),
                'tokens' => (int)($entry['total_tokens'] ?? 0),
                'cost' => number_format((float)($entry['cost'] ?? 0), 4),
                'success' => (bool)($entry['success'] ?? false),
                'duration' => (int)($entry['duration_ms'] ?? 0),
            ];
        }
        return $items;
    }
}
