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

use B13\Aim\Domain\Repository\RequestLogRepository;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Provides success vs failure counts for the dashboard doughnut chart.
 */
class SuccessRateDataProvider implements ChartDataProviderInterface
{
    public function __construct(
        private readonly RequestLogRepository $logRepository,
    ) {}

    public function getChartData(): array
    {
        $stats = $this->logRepository->getStatistics();
        $total = (int)($stats['total_requests'] ?? 0);
        $successRate = (float)($stats['success_rate'] ?? 0);
        $successful = (int)round($total * $successRate / 100);
        $failed = $total - $successful;

        return [
            'labels' => ['Successful', 'Failed'],
            'datasets' => [
                [
                    'backgroundColor' => ['#2a9960', '#d64545'],
                    'data' => [$successful, $failed],
                ],
            ],
        ];
    }
}
