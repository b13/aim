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
 * Provides request count per provider for the dashboard doughnut chart.
 */
class ProviderUsageDataProvider implements ChartDataProviderInterface
{
    private const CHART_COLORS = ['#ff8700', '#a4276a', '#1a568f', '#4c7e3a', '#69bbb5'];

    public function __construct(
        private readonly RequestLogRepository $logRepository,
    ) {}

    public function getChartData(): array
    {
        $labels = $data = [];
        foreach ($this->logRepository->getStatisticsByProvider() as $row) {
            $labels[] = $row['provider_identifier'] ?: 'unknown';
            $data[] = (int)$row['request_count'];
        }
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'backgroundColor' => self::CHART_COLORS,
                    'data' => $data,
                ],
            ],
        ];
    }
}
