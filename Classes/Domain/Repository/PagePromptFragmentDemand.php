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

use B13\Aim\Prompt\PromptFragmentScope;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Filter/sort/pagination demand for the "pages with prompt fragments"
 * listing. Mirrors ProviderConfigurationDemand's shape so the listing
 * follows the same filter/search/pagination conventions as the Providers
 * and Request Log modules.
 */
class PagePromptFragmentDemand implements SortableDemandInterface
{
    protected const ORDER_DESCENDING = 'desc';
    protected const ORDER_ASCENDING = 'asc';
    protected const DEFAULT_ORDER_FIELD = 'title';
    protected const ORDER_FIELDS = ['title'];

    protected int $limit = 15;

    public function __construct(
        protected int $page = 1,
        protected string $orderField = self::DEFAULT_ORDER_FIELD,
        protected string $orderDirection = self::ORDER_ASCENDING,
        protected ?PromptFragmentScope $scope = null,
        protected string $search = '',
    ) {
        if (!in_array($orderField, self::ORDER_FIELDS, true)) {
            $orderField = self::DEFAULT_ORDER_FIELD;
        }
        $this->orderField = $orderField;
        if (!in_array($orderDirection, [self::ORDER_DESCENDING, self::ORDER_ASCENDING], true)) {
            $orderDirection = self::ORDER_ASCENDING;
        }
        $this->orderDirection = $orderDirection;
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $page = (int)($request->getQueryParams()['page'] ?? $request->getParsedBody()['page'] ?? 1);
        $orderField = (string)($request->getQueryParams()['orderField'] ?? $request->getParsedBody()['orderField'] ?? self::DEFAULT_ORDER_FIELD);
        $orderDirection = (string)($request->getQueryParams()['orderDirection'] ?? $request->getParsedBody()['orderDirection'] ?? self::ORDER_ASCENDING);
        $demand = $request->getQueryParams()['demand'] ?? $request->getParsedBody()['demand'] ?? [];
        if (!is_array($demand) || $demand === []) {
            return new self($page, $orderField, $orderDirection);
        }
        $scope = PromptFragmentScope::tryFrom((string)($demand['scope'] ?? ''));
        $search = (string)($demand['search'] ?? '');
        return new self($page, $orderField, $orderDirection, $scope, $search);
    }

    /**
     * @return list<string>
     */
    public static function getOrderFields(): array
    {
        return self::ORDER_FIELDS;
    }

    public function getOrderField(): string
    {
        return $this->orderField;
    }

    public function getOrderDirection(): string
    {
        return $this->orderDirection;
    }

    public function getScope(): ?PromptFragmentScope
    {
        return $this->scope;
    }

    /**
     * Plain string accessor for Fluid's `==` comparison in the filter
     * form (`{option.value} == {demand.scopeValue}`) — a native PHP enum
     * has no `__toString()`, so comparing the raw enum from getScope()
     * against a string value would never match.
     */
    public function getScopeValue(): string
    {
        return $this->scope?->value ?? '';
    }

    public function hasScope(): bool
    {
        return $this->scope !== null;
    }

    public function getSearch(): string
    {
        return $this->search;
    }

    public function hasSearch(): bool
    {
        return $this->search !== '';
    }

    public function hasConstraints(): bool
    {
        return $this->hasScope() || $this->hasSearch();
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    public function getParameters(): array
    {
        $parameters = [];
        if ($this->hasScope()) {
            $parameters['scope'] = $this->scope->value;
        }
        if ($this->hasSearch()) {
            $parameters['search'] = $this->search;
        }
        return $parameters;
    }
}
