<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend;

use B13\Aim\Domain\Repository\SortableDemandInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Builds the asc/desc sort URLs per sortable column for a backend listing,
 * preserving all active filters.
 *
 * Shared by multiple controllers with a sortable lists: each just supplies its own demand object and route.
 */
final class SortUrlBuilder
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * @param array<string, mixed> $additionalParameters merged in verbatim (not
     *        namespaced under `demand[...]`) — e.g. a page-tree module's
     *        currently selected `id`, so sorting doesn't drop it. Defaults to
     *        empty so existing callers are unaffected.
     * @return array<string, array{ascUrl: string, descUrl: string, active: bool, direction: string}>
     */
    public function build(SortableDemandInterface $demand, string $route, array $additionalParameters = []): array
    {
        $filterParams = $additionalParameters;
        foreach ($demand->getParameters() as $key => $value) {
            $filterParams['demand[' . $key . ']'] = $value;
        }

        $sortUrls = [];
        foreach ($demand::getOrderFields() as $field) {
            $isActive = $demand->getOrderField() === $field;
            $sortUrls[$field] = [
                'ascUrl' => (string)$this->uriBuilder->buildUriFromRoute($route, array_merge($filterParams, [
                    'orderField' => $field,
                    'orderDirection' => 'asc',
                ])),
                'descUrl' => (string)$this->uriBuilder->buildUriFromRoute($route, array_merge($filterParams, [
                    'orderField' => $field,
                    'orderDirection' => 'desc',
                ])),
                'active' => $isActive,
                'direction' => $isActive ? $demand->getOrderDirection() : '',
            ];
        }
        return $sortUrls;
    }
}
