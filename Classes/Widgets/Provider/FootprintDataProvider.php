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
use B13\Aim\Footprint\Display;
use B13\Aim\Footprint\Format;

/**
 * Estimated footprint of all logged requests, in total and per provider.
 */
class FootprintDataProvider
{
    public function __construct(
        private readonly RequestLogRepository $repository,
    ) {}

    /**
     * @return array{total: array<string, mixed>, providers: list<array<string, mixed>>, routing: array{key: string, arguments: list<string>}|null}
     */
    public function getData(): array
    {
        $statistics = $this->repository->getStatistics();
        $providers = array_map(
            static function (array $row): array {
                $identifier = $row['provider_identifier'];
                unset($row['provider_identifier']);
                return ['identifier' => $identifier] + Format::summary($row);
            },
            $this->repository->getFootprintByProvider(),
        );

        return [
            'total' => Display::total($statistics),
            'providers' => $providers,
            'routing' => Display::routing($statistics),
        ];
    }
}
