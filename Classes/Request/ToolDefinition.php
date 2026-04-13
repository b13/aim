<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request;

/**
 * Defines a tool/function that the AI model can invoke during processing.
 *
 * Tools allow the AI to request specific actions or data from the application,
 * enabling agentic workflows where the model can interact with the system.
 */
final class ToolDefinition
{
    /**
     * @param string $name Unique tool name (e.g. 'get_page_content')
     * @param string $description Human-readable description of what the tool does
     * @param array $parameters JSON Schema describing the expected parameters
     * @param bool $strict Whether the model must strictly adhere to the schema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters = [],
        public readonly bool $strict = false,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters ?: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'additionalProperties' => false,
                ],
                'strict' => $this->strict,
            ],
        ];
    }
}
