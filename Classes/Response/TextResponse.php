<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Response;

/**
 * Response returned by all AI operations.
 *
 * Contains the generated content, token usage statistics, the raw provider
 * response, and any errors. Base class for ConversationResponse,
 * ToolCallingResponse, and EmbeddingResponse.
 */
class TextResponse
{
    public function __construct(
        public readonly string $content,
        public readonly AiUsageStatistics $usage = new AiUsageStatistics(),
        public readonly array $rawResponse = [],
        public readonly array $errors = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->errors === [] && $this->content !== '';
    }
}
