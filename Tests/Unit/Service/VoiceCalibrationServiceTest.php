<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Service;

use B13\Aim\Service\VoiceCalibrationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * calibrate()'s length-validation short-circuits before ever touching the
 * `Ai` dependency, so these cases build the service via
 * newInstanceWithoutConstructor() (mirroring the "final class placeholder
 * for code the path under test never reaches" convention already used in
 * ProviderControllerTest/PromptManagementControllerTest) rather than mocking
 * `Ai`. A real dispatch-and-parse round trip isn't unit-testable without a
 * live/fake provider, same situation GradingService is in (see its own
 * functional test, which likewise only covers parseJudgeOutput() directly).
 */
final class VoiceCalibrationServiceTest extends TestCase
{
    #[Test]
    public function rejectsTooShortInput(): void
    {
        $service = $this->uninitializedService();
        $result = $service->calibrate('too short');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('longer sample', $result['message']);
    }

    #[Test]
    public function rejectsEmptyInput(): void
    {
        $service = $this->uninitializedService();
        $result = $service->calibrate('   ');

        self::assertFalse($result['ok']);
    }

    #[Test]
    public function rejectsTooLongInput(): void
    {
        $service = $this->uninitializedService();
        $result = $service->calibrate(str_repeat('a', 12001));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('too long', $result['message']);
    }

    #[Test]
    public function parseResultAcceptsCleanJson(): void
    {
        $parsed = $this->invokeParseResult('{"tone": "Warm and direct.", "examples": "Q: Hi\nA: Hello there!"}');
        self::assertSame('Warm and direct.', $parsed['tone']);
        self::assertSame("Q: Hi\nA: Hello there!", $parsed['examples']);
    }

    #[Test]
    public function parseResultStripsMarkdownFences(): void
    {
        $parsed = $this->invokeParseResult("```json\n{\"tone\": \"Crisp.\", \"examples\": \"\"}\n```");
        self::assertSame('Crisp.', $parsed['tone']);
    }

    #[Test]
    public function parseResultReturnsNullWhenToneMissing(): void
    {
        self::assertNull($this->invokeParseResult('{"examples": "x"}'));
    }

    #[Test]
    public function parseResultReturnsNullOnMalformedJson(): void
    {
        self::assertNull($this->invokeParseResult('not json at all'));
    }

    private function uninitializedService(): VoiceCalibrationService
    {
        return (new \ReflectionClass(VoiceCalibrationService::class))->newInstanceWithoutConstructor();
    }

    private function invokeParseResult(string $raw): ?array
    {
        $service = $this->uninitializedService();
        $method = new \ReflectionMethod($service, 'parseResult');
        return $method->invoke($service, $raw);
    }
}
