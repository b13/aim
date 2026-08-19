<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Middleware;

use B13\AiLabel\Service\AiLabelApi;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Middleware\AiLabelMiddleware;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * B13\AiLabel\Service\AiLabelApi is not a real dependency of aim (ai_label is
 * a sibling extension, never installed in aim's own isolated test run), so
 * FakeAiLabelApi.php declares a stand-in under its exact real namespace -
 * see that file's docblock. This is the only way to functionally exercise
 * the "ai_label is actually installed" branch: AiLabelMiddlewareTest (unit)
 * only ever sees class_exists() return false.
 */
final class AiLabelMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private AiLabelApi $fakeApi;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Fixtures/FakeAiLabelApi.php';
        $this->fakeApi = new AiLabelApi();
        GeneralUtility::addInstance(AiLabelApi::class, $this->fakeApi);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

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
    public function flagsRecordAsAiCreatedForASuccessfulResponse(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => 42, 'origin' => 'created'],
        ]);
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame(
            [['method' => 'aiCreated', 'table' => 'sys_file_metadata', 'uid' => 42]],
            $this->fakeApi->calls,
        );
    }

    #[Test]
    public function flagsRecordAsAiModifiedWhenOriginIsModified(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'tt_content', 'uid' => 7, 'origin' => 'modified'],
        ]);
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame(
            [['method' => 'aiModified', 'table' => 'tt_content', 'uid' => 7]],
            $this->fakeApi->calls,
        );
    }

    #[Test]
    public function doesNotCallAiLabelApiWhenResponseFailed(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => 42],
        ]);
        $response = new TextResponse('', errors: ['boom']);
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame([], $this->fakeApi->calls);
    }

    #[Test]
    public function doesNotCallAiLabelApiWhenNoMetadataIsSet(): void
    {
        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi');
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $result = $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame([], $this->fakeApi->calls);
        self::assertSame($response, $result);
    }

    #[Test]
    public function doesNotCallAiLabelApiSynchronouslyForAnUndrainedStreamingResponse(): void
    {
        $config = $this->createConfig();
        $request = new ConversationRequest(
            configuration: $config,
            messages: [new UserMessage('Hi')],
            stream: true,
            metadata: ['aiLabel' => ['table' => 'sys_file_metadata', 'uid' => 42]],
        );
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'chunk';
        })(), $config);
        $response = new ConversationResponse('', streamIterator: $streamIterator);
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame([], $this->fakeApi->calls);
    }

    #[Test]
    public function appliesLabelOffTheDrainedIteratorOnceStreamIsConsumed(): void
    {
        // applyLabelForDrainedStream() is what the shutdown function registered
        // by deferLabel() actually calls - tested directly here since PHP only
        // invokes shutdown functions at the end of the whole test process, the
        // same constraint CostTrackingMiddlewareTest works under.
        $config = $this->createConfig();
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            yield 'the accumulated content';
        })(), $config);
        iterator_to_array($streamIterator, false);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $target = ['table' => 'sys_file_metadata', 'uid' => 42, 'origin' => 'created'];
        (new \ReflectionMethod($middleware, 'applyLabelForDrainedStream'))->invoke($middleware, $streamIterator, $target);

        self::assertSame(
            [['method' => 'aiCreated', 'table' => 'sys_file_metadata', 'uid' => 42]],
            $this->fakeApi->calls,
        );
    }

    #[Test]
    public function doesNotApplyLabelWhenDrainedIteratorHasNoAccumulatedContent(): void
    {
        $config = $this->createConfig();
        $streamIterator = new StreamChunkIterator((function (): \Generator {
            // No string chunks at all, so getAccumulatedContent() stays empty.
            if (false) {
                yield '';
            }
        })(), $config);
        iterator_to_array($streamIterator, false);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $target = ['table' => 'sys_file_metadata', 'uid' => 42, 'origin' => 'created'];
        (new \ReflectionMethod($middleware, 'applyLabelForDrainedStream'))->invoke($middleware, $streamIterator, $target);

        self::assertSame([], $this->fakeApi->calls);
    }

    #[Test]
    public function loggedFailureFromAiLabelApiDoesNotBreakTheResponse(): void
    {
        $throwingApi = new class extends AiLabelApi {
            public function aiCreated(string $table, int $uid, ?BackendUserAuthentication $user = null): void
            {
                throw new \RuntimeException('No backend user available.', 1785502864);
            }
        };
        // The fake queued in setUp() was never consumed by an earlier
        // makeInstance() call in this test - purge it first so the queue
        // holds only $throwingApi, not both (addInstance() is FIFO).
        GeneralUtility::purgeInstances();
        GeneralUtility::addInstance(AiLabelApi::class, $throwingApi);

        $config = $this->createConfig();
        $request = new TextGenerationRequest(configuration: $config, prompt: 'Hi', metadata: [
            'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => 42],
        ]);
        $response = new TextResponse('hello');
        $next = new AiMiddlewareHandler(static fn() => $response);

        $middleware = new AiLabelMiddleware(new NullLogger());
        $result = $middleware->process($request, $this->createMock(AiProviderInterface::class), $config, $next);

        self::assertSame($response, $result);
    }
}
