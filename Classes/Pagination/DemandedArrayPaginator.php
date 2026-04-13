<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Pagination;

use TYPO3\CMS\Core\Pagination\AbstractPaginator;

final class DemandedArrayPaginator extends AbstractPaginator
{
    private array $items;
    private int $allCount;

    private array $paginatedItems = [];

    public function __construct(
        array $items,
        int $currentPageNumber = 1,
        int $itemsPerPage = 10,
        int $allCount = 0
    ) {
        $this->items = $items;
        $this->setCurrentPageNumber($currentPageNumber);
        $this->setItemsPerPage($itemsPerPage);
        $this->allCount = $allCount;

        $this->updateInternalState();
    }

    public function getPaginatedItems(): iterable
    {
        return $this->paginatedItems;
    }

    protected function updatePaginatedItems(int $itemsPerPage, int $offset): void
    {
        $this->paginatedItems = $this->items;
    }

    protected function getTotalAmountOfItems(): int
    {
        return $this->allCount;
    }

    protected function getAmountOfItemsOnCurrentPage(): int
    {
        return count($this->paginatedItems);
    }
}
