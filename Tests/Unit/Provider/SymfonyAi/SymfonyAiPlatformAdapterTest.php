<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Provider\SymfonyAi;

use B13\Aim\Provider\SymfonyAi\SymfonyAiPlatformAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SymfonyAiPlatformAdapterTest extends TestCase
{
    public static function maxTokensKeyProvider(): \Generator
    {
        yield 'OpenAI Responses API' => [
            'Symfony\\AI\\Platform\\Bridge\\OpenAi\\PlatformFactory',
            'max_output_tokens',
        ];
        yield 'OpenResponses bridge' => [
            'Symfony\\AI\\Platform\\Bridge\\OpenResponses\\PlatformFactory',
            'max_output_tokens',
        ];
        yield 'Anthropic Messages API' => [
            'Symfony\\AI\\Platform\\Bridge\\Anthropic\\PlatformFactory',
            'max_tokens',
        ];
        yield 'Mistral' => [
            'Symfony\\AI\\Platform\\Bridge\\Mistral\\PlatformFactory',
            'max_tokens',
        ];
        yield 'Ollama' => [
            'Symfony\\AI\\Platform\\Bridge\\Ollama\\PlatformFactory',
            'max_tokens',
        ];
        yield 'unknown bridge falls back to max_tokens' => [
            'Acme\\AiBridge\\PlatformFactory',
            'max_tokens',
        ];
    }

    #[Test]
    #[DataProvider('maxTokensKeyProvider')]
    public function resolveMaxTokensKeyMapsBridgeToCorrectOptionName(string $factoryClass, string $expectedKey): void
    {
        self::assertSame($expectedKey, SymfonyAiPlatformAdapter::resolveMaxTokensKey($factoryClass));
    }
}
