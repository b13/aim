<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Domain\Repository;

/**
 * Contract for demand objects that back a sortable backend list (RequestLogDemand,
 * ProviderConfigurationDemand). Lets SortUrlBuilder build sort links generically,
 * without knowing which specific listing it's building them for.
 */
interface SortableDemandInterface
{
    public function getOrderField(): string;

    public function getOrderDirection(): string;

    /**
     * Active filter/constraint parameters (not sorting-related), reused so
     * sort links don't reset whatever's currently filtered.
     *
     * @return array<string, string>
     */
    public function getParameters(): array;

    /**
     * @return list<string>
     */
    public static function getOrderFields(): array;
}
