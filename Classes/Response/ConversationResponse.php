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
 * Response for conversation and streaming requests.
 *
 * Extends TextResponse with an optional streamIterator for token-by-token
 * streaming. When streaming, content may be empty initially and the caller
 * iterates the stream to collect chunks.
 */
class ConversationResponse extends TextResponse
{
    public function __construct(
        string $content,
        AiUsageStatistics $usage = new AiUsageStatistics(),
        array $rawResponse = [],
        array $errors = [],
        public readonly ?\Traversable $streamIterator = null,
    ) {
        parent::__construct($content, $usage, $rawResponse, $errors);
    }

    public function isStreaming(): bool
    {
        return $this->streamIterator !== null;
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [] && ($this->content !== '' || $this->isStreaming());
    }
}
