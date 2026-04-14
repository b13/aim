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

use TYPO3\CMS\Dashboard\Widgets\ButtonProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\ElementAttributesInterface;

/**
 * Provides a button that navigates to the AiM Request Log module.
 */
class RequestLogButtonProvider implements ButtonProviderInterface, ElementAttributesInterface
{
    public function __construct(
        private readonly string $title,
    ) {}

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLink(): string
    {
        return '';
    }

    public function getTarget(): string
    {
        return '';
    }

    public function getElementAttributes(): array
    {
        return [
            'data-dispatch-action' => 'TYPO3.ModuleMenu.showModule',
            'data-dispatch-args-list' => 'aim_request_log',
        ];
    }
}
