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
 * Response containing vector embeddings.
 *
 * Each embedding corresponds to one input text from the request,
 * in the same order.
 */
class EmbeddingResponse extends TextResponse
{
    /**
     * @param list<list<float>> $embeddings Vector embeddings, one per input text
     */
    public function __construct(
        public readonly array $embeddings = [],
        AiUsageStatistics $usage = new AiUsageStatistics(),
        array $rawResponse = [],
        array $errors = [],
    ) {
        parent::__construct('', $usage, $rawResponse, $errors);
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [] && $this->embeddings !== [];
    }
}
