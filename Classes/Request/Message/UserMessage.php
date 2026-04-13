<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request\Message;

/**
 * A message from the user. Content can be a string or an array
 * for multimodal input (e.g. text + image for vision requests).
 */
final class UserMessage extends AbstractMessage
{
    public function __construct(string|array $content)
    {
        parent::__construct('user', $content);
    }
}
