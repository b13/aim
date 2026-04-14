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

class RequestLogDemand
{
    protected const ORDER_DESCENDING = 'desc';
    protected const ORDER_ASCENDING = 'asc';
    protected const DEFAULT_ORDER_FIELD = 'crdate';
    protected const ORDER_FIELDS = ['crdate', 'cost', 'duration_ms', 'request_type', 'extension_key', 'total_tokens'];

    protected int $limit = 25;

    public function __construct(
        protected int $page = 1,
        protected string $orderField = self::DEFAULT_ORDER_FIELD,
        protected string $orderDirection = self::ORDER_DESCENDING,
        protected string $providerIdentifier = '',
        protected string $extensionKey = '',
        protected string $requestType = '',
        protected string $modelUsed = '',
        protected ?bool $success = null,
        protected int $dateFrom = 0,
        protected int $dateTo = 0,
    ) {
        if (!in_array($orderField, self::ORDER_FIELDS, true)) {
            $orderField = self::DEFAULT_ORDER_FIELD;
        }
        $this->orderField = $orderField;
        if (!in_array($orderDirection, [self::ORDER_DESCENDING, self::ORDER_ASCENDING], true)) {
            $orderDirection = self::ORDER_DESCENDING;
        }
        $this->orderDirection = $orderDirection;
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $page = (int)($request->getQueryParams()['page'] ?? $request->getParsedBody()['page'] ?? 1);
        $orderField = (string)($request->getQueryParams()['orderField'] ?? $request->getParsedBody()['orderField'] ?? self::DEFAULT_ORDER_FIELD);
        $orderDirection = (string)($request->getQueryParams()['orderDirection'] ?? $request->getParsedBody()['orderDirection'] ?? self::ORDER_DESCENDING);
        $demand = $request->getQueryParams()['demand'] ?? $request->getParsedBody()['demand'] ?? [];
        if (!is_array($demand) || $demand === []) {
            return new self($page, $orderField, $orderDirection);
        }
        return new self(
            $page,
            $orderField,
            $orderDirection,
            (string)($demand['provider_identifier'] ?? ''),
            (string)($demand['extension_key'] ?? ''),
            (string)($demand['request_type'] ?? ''),
            (string)($demand['model_used'] ?? ''),
            isset($demand['success']) && $demand['success'] !== '' ? (bool)(int)$demand['success'] : null,
            (int)($demand['date_from'] ?? 0),
            (int)($demand['date_to'] ?? 0),
        );
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
        return self::ORDER_DESCENDING;
    }

    public function getReverseOrderDirection(): string
    {
        return $this->orderDirection === self::ORDER_ASCENDING ? self::ORDER_DESCENDING : self::ORDER_ASCENDING;
    }

    public function getProviderIdentifier(): string
    {
        return $this->providerIdentifier;
    }

    public function hasProviderIdentifier(): bool
    {
        return $this->providerIdentifier !== '';
    }

    public function getExtensionKey(): string
    {
        return $this->extensionKey;
    }

    public function hasExtensionKey(): bool
    {
        return $this->extensionKey !== '';
    }

    public function getRequestType(): string
    {
        return $this->requestType;
    }

    public function hasRequestType(): bool
    {
        return $this->requestType !== '';
    }

    public function getModelUsed(): string
    {
        return $this->modelUsed;
    }

    public function hasModelUsed(): bool
    {
        return $this->modelUsed !== '';
    }

    public function getSuccess(): ?bool
    {
        return $this->success;
    }

    public function hasSuccess(): bool
    {
        return $this->success !== null;
    }

    public function getDateFrom(): int
    {
        return $this->dateFrom;
    }

    public function hasDateFrom(): bool
    {
        return $this->dateFrom > 0;
    }

    public function getDateTo(): int
    {
        return $this->dateTo;
    }

    public function hasDateTo(): bool
    {
        return $this->dateTo > 0;
    }

    public function hasConstraints(): bool
    {
        return $this->hasProviderIdentifier()
            || $this->hasExtensionKey()
            || $this->hasRequestType()
            || $this->hasModelUsed()
            || $this->hasSuccess()
            || $this->hasDateFrom()
            || $this->hasDateTo();
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
        if ($this->hasProviderIdentifier()) {
            $parameters['provider_identifier'] = $this->getProviderIdentifier();
        }
        if ($this->hasExtensionKey()) {
            $parameters['extension_key'] = $this->getExtensionKey();
        }
        if ($this->hasRequestType()) {
            $parameters['request_type'] = $this->getRequestType();
        }
        if ($this->hasModelUsed()) {
            $parameters['model_used'] = $this->getModelUsed();
        }
        if ($this->hasSuccess()) {
            $parameters['success'] = $this->getSuccess() ? '1' : '0';
        }
        if ($this->hasDateFrom()) {
            $parameters['date_from'] = $this->getDateFrom();
        }
        if ($this->hasDateTo()) {
            $parameters['date_to'] = $this->getDateTo();
        }
        return $parameters;
    }
}
