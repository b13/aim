<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Provider;

/**
 * An ordered list of resolved providers for fallback scenarios.
 *
 * The primary provider is tried first. If it fails, the middleware
 * pipeline can iterate through the fallbacks in order.
 */
final class FallbackChain implements \IteratorAggregate, \Countable
{
    /** @var list<ResolvedProvider> */
    private readonly array $all;

    public function __construct(
        private readonly ResolvedProvider $primary,
        ResolvedProvider ...$fallbacks,
    ) {
        $this->all = [$this->primary, ...$fallbacks];
    }

    public function getPrimary(): ResolvedProvider
    {
        return $this->primary;
    }

    /**
     * @return list<ResolvedProvider>
     */
    public function getFallbacks(): array
    {
        return array_slice($this->all, 1);
    }

    /**
     * @return list<ResolvedProvider>
     */
    public function getAll(): array
    {
        return $this->all;
    }

    public function count(): int
    {
        return count($this->all);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all);
    }
}
