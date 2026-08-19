<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Middleware;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Middleware\AiLabelMiddleware;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * B13\AiLabel\Service\AiLabelApi is not autoloadable in this extension's own
 * isolated test run (ai_label is a sibling extension, not a dependency), so
 * class_exists(AiLabelApi::class) is always false here - every case below
 * exercises the "not installed" / "no opt-in metadata" no-op paths. The
 * actual labelling call is exercised manually against the real, installed
 * ai_label extension (see README).
 */
final class AiLabelMiddlewareTest extends TestCase
{
    private function createConfig(): ProviderConfiguration
    {
        return new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ]);
    }

    #[Test]
    public function passesResponseThroughUnchangedWhenNoAiLabelMetadataIsSet(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi');
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $result = $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame($response, $result);
    }

    #[Test]
    public function passesResponseThroughUnchangedWhenAiLabelExtensionIsNotInstalled(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => 42, 'origin' => 'created'],
        ]);
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $result = $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame($response, $result);
    }

    #[Test]
    public function resolveTargetIgnoresMetadataMissingTableOrUid(): void
    {
        $middleware = new AiLabelMiddleware(new NullLogger());
        $method = new \ReflectionMethod($middleware, 'resolveTarget');

        $config = $this->createConfig();
        $withoutUid = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'sys_file_metadata'],
        ]);
        self::assertNull($method->invoke($middleware, $withoutUid));

        $withoutTable = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['uid' => 42],
        ]);
        self::assertNull($method->invoke($middleware, $withoutTable));
    }

    #[Test]
    public function resolveTargetDefaultsOriginToCreated(): void
    {
        $middleware = new AiLabelMiddleware(new NullLogger());
        $method = new \ReflectionMethod($middleware, 'resolveTarget');

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => 42],
        ]);

        self::assertSame(
            ['table' => 'sys_file_metadata', 'uid' => 42, 'origin' => 'created'],
            $method->invoke($middleware, $request),
        );
    }

    #[Test]
    public function resolveTargetFallsBackToCreatedForAnUnknownOrigin(): void
    {
        $middleware = new AiLabelMiddleware(new NullLogger());
        $method = new \ReflectionMethod($middleware, 'resolveTarget');

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => 42, 'origin' => 'deleted'],
        ]);

        self::assertSame(
            ['table' => 'sys_file_metadata', 'uid' => 42, 'origin' => 'created'],
            $method->invoke($middleware, $request),
        );
    }
}
