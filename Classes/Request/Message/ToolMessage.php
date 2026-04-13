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
 * A message containing the output of a tool execution,
 * sent back to the model for continued processing.
 */
final class ToolMessage extends AbstractMessage
{
    public function __construct(
        string $content,
        public readonly string $toolCallId,
    ) {
        parent::__construct('tool', $content);
    }

    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'tool_call_id' => $this->toolCallId,
            'content' => $this->content,
        ];
    }
}
