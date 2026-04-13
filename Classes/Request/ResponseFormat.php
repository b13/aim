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
 * Defines the expected response format for structured AI output.
 *
 * Supports plain text, generic JSON, or strict JSON Schema output.
 * When using JSON Schema, the AI model is constrained to return
 * responses matching the given schema exactly.
 */
final class ResponseFormat
{
    private function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly array $schema,
    ) {}

    /**
     * Default text response format (no constraints).
     */
    public static function text(): self
    {
        return new self('text', '', []);
    }

    /**
     * Request a valid JSON response (no schema enforcement).
     */
    public static function json(): self
    {
        return new self('json_object', '', []);
    }

    /**
     * Request a structured JSON response matching a specific schema.
     *
     * @param string $name Schema name identifier
     * @param array $schema JSON Schema definition (properties, required, etc.)
     */
    public static function jsonSchema(string $name, array $schema): self
    {
        return new self('json_schema', $name, $schema);
    }

    public function toArray(): array
    {
        if ($this->type === 'text') {
            return ['type' => 'text'];
        }
        if ($this->type === 'json_object') {
            return ['type' => 'json_object'];
        }
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $this->name,
                'schema' => $this->schema,
                'strict' => true,
            ],
        ];
    }
}
