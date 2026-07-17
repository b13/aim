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
 * Response containing one or more generated images.
 */
class ImageGenerationResponse extends TextResponse
{
    /**
     * @param list<GeneratedImage> $images
     */
    public function __construct(
        public readonly array $images = [],
        AiUsageStatistics $usage = new AiUsageStatistics(),
        array $rawResponse = [],
        array $errors = [],
    ) {
        parent::__construct('', $usage, $rawResponse, $errors);
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [] && $this->images !== [];
    }
}
