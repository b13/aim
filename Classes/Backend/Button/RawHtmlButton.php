<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend\Button;

use TYPO3\CMS\Backend\Template\Components\Buttons\ButtonInterface;

/**
 * Workaround to not have the button wrapped in a <span>
 */
class RawHtmlButton implements ButtonInterface
{
    protected string $html = '';

    public function setHtml(string $html): static
    {
        $this->html = $html;
        return $this;
    }

    public function isValid(): bool
    {
        return $this->html !== '';
    }

    public function getType(): string
    {
        return static::class;
    }

    public function render(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
