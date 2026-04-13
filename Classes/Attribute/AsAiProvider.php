<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class AsAiProvider
{
    public const TAG_NAME = 'b13.aim.ai_provider';

    /**
     * @param array<string, string> $supportedModels Model ID => label
     * @param array<string, list<class-string>> $modelCapabilities Model ID => capability interfaces.
     *        Models not listed inherit all provider-level capabilities.
     *        Models listed get ONLY the specified capabilities.
     */
    public function __construct(
        public string $identifier,
        public string $name = '',
        public string $description = '',
        public string $iconIdentifier = '',
        public array $supportedModels = [],
        public array $features = [],
        public array $modelCapabilities = [],
    ) {}
}
