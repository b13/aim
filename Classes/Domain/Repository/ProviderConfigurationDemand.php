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

use Psr\Http\Message\ServerRequestInterface;

class ProviderConfigurationDemand
{
    protected const ORDER_DESCENDING = 'desc';
    protected const ORDER_ASCENDING = 'asc';
    protected const DEFAULT_ORDER_FIELD = 'title';
    protected const ORDER_FIELDS = ['title', 'ai_provider', 'model', 'total_cost'];

    protected int $limit = 15;

    public function __construct(
        protected int $page = 1,
        protected string $orderField = self::DEFAULT_ORDER_FIELD,
        protected string $orderDirection = self::ORDER_ASCENDING,
        protected string $title = '',
        protected string $aiProvider = ''
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
        $title = (string)($demand['title'] ?? '');
        $aiProvider = (string)($demand['ai_provider'] ?? '');
        return new self($page, $orderField, $orderDirection, $title, $aiProvider);
    }

    public function getOrderField(): string
    {
        return $this->orderField;
    }

    public function getOrderDirection(): string
    {
        return $this->orderDirection;
    }

    public function getDefaultOrderDirection(): string
    {
        return self::ORDER_ASCENDING;
    }

    public function getReverseOrderDirection(): string
    {
        return $this->orderDirection === self::ORDER_ASCENDING ? self::ORDER_DESCENDING : self::ORDER_ASCENDING;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function hasTitle(): bool
    {
        return $this->title !== '';
    }

    public function getAiProvider(): string
    {
        return $this->aiProvider;
    }

    public function hasAiProvider(): bool
    {
        return $this->aiProvider !== '';
    }

    public function hasConstraints(): bool
    {
        return $this->hasTitle()
            || $this->hasAiProvider();
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
        if ($this->hasTitle()) {
            $parameters['title'] = $this->getTitle();
        }
        if ($this->hasAiProvider()) {
            $parameters['ai_provider'] = $this->getAiProvider();
        }
        return $parameters;
    }
}
