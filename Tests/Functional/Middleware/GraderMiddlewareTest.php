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

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Governance\PrivacyLevel;
use B13\Aim\Grading\GradeStatus;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\GraderMiddleware;
use B13\Aim\Middleware\RequestContext;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Response\TextResponse;
use B13\Aim\Service\GradingService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class GraderMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function marksRowPendingWhenGradingEnabledAndResponseSuccessful(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logUid = $logRepo->log([
            'crdate' => time(),
            'request_type' => 'ConversationRequest',
            'provider_identifier' => 'openai',
            'configuration_uid' => 1,
            'success' => 1,
            'response_content' => 'a response',
        ]);
        self::assertGreaterThan(0, $logUid);

        $context = new RequestContext();
        $context->logUid = $logUid;

        $stubGrader = $this->buildStubGradingService();
        $middleware = new GraderMiddleware($stubGrader, $logRepo, new NullLogger());

        $config = $this->buildConfiguration([
            'grading_enabled' => 1,
            'judge_configuration_uid' => 99,
            'privacy_level' => 'standard',
        ]);

        $request = $this->buildConversationRequest($config);
        $response = new TextResponse('hello', errors: []);

        $next = new AiMiddlewareHandler(
            static fn() => $response,
            $context,
        );

        $middleware->process($request, $this->stubProvider(), $config, $next);

        $row = $logRepo->findByUid($logUid);
        self::assertSame(GradeStatus::Pending->value, $row['grade_status']);
    }

    #[Test]
    public function doesNotMarkPendingWhenGradingDisabled(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logUid = $logRepo->log(['crdate' => time(), 'configuration_uid' => 1]);

        $context = new RequestContext();
        $context->logUid = $logUid;
        $middleware = new GraderMiddleware($this->buildStubGradingService(), $logRepo, new NullLogger());

        $config = $this->buildConfiguration(['grading_enabled' => 0]);
        $request = $this->buildConversationRequest($config);
        $next = new AiMiddlewareHandler(static fn() => new TextResponse('x'), $context);

        $middleware->process($request, $this->stubProvider(), $config, $next);

        $row = $logRepo->findByUid($logUid);
        self::assertSame(GradeStatus::None->value, $row['grade_status']);
    }

    #[Test]
    public function doesNotMarkPendingWhenRecursionFlagIsSet(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logUid = $logRepo->log(['crdate' => time(), 'configuration_uid' => 1]);

        $context = new RequestContext();
        $context->logUid = $logUid;
        $middleware = new GraderMiddleware($this->buildStubGradingService(), $logRepo, new NullLogger());

        $config = $this->buildConfiguration([
            'grading_enabled' => 1,
            'judge_configuration_uid' => 99,
        ]);
        $request = new ConversationRequest(
            configuration: $config,
            messages: [new UserMessage('hi')],
            metadata: ['_aim_grading' => true],
        );
        $next = new AiMiddlewareHandler(static fn() => new TextResponse('x'), $context);

        $middleware->process($request, $this->stubProvider(), $config, $next);

        $row = $logRepo->findByUid($logUid);
        self::assertSame(GradeStatus::None->value, $row['grade_status']);
    }

    #[Test]
    public function doesNotMarkPendingWhenPrivacyIsReduced(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logUid = $logRepo->log(['crdate' => time(), 'configuration_uid' => 1]);

        $context = new RequestContext();
        $context->logUid = $logUid;
        $middleware = new GraderMiddleware($this->buildStubGradingService(), $logRepo, new NullLogger());

        $config = $this->buildConfiguration([
            'grading_enabled' => 1,
            'judge_configuration_uid' => 99,
            'privacy_level' => 'reduced',
        ]);
        $request = $this->buildConversationRequest($config);
        $next = new AiMiddlewareHandler(static fn() => new TextResponse('x'), $context);

        $middleware->process($request, $this->stubProvider(), $config, $next);

        $row = $logRepo->findByUid($logUid);
        self::assertSame(GradeStatus::None->value, $row['grade_status']);
    }

    #[Test]
    public function doesNotMarkPendingForEmbeddingRequests(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logUid = $logRepo->log(['crdate' => time(), 'configuration_uid' => 1]);

        $context = new RequestContext();
        $context->logUid = $logUid;
        $middleware = new GraderMiddleware($this->buildStubGradingService(), $logRepo, new NullLogger());

        $config = $this->buildConfiguration([
            'grading_enabled' => 1,
            'judge_configuration_uid' => 99,
        ]);
        $request = new EmbeddingRequest(
            configuration: $config,
            input: ['some text'],
        );
        $next = new AiMiddlewareHandler(static fn() => new TextResponse('x'), $context);

        $middleware->process($request, $this->stubProvider(), $config, $next);

        $row = $logRepo->findByUid($logUid);
        self::assertSame(GradeStatus::None->value, $row['grade_status']);
    }

    #[Test]
    public function doesNotMarkPendingWhenResponseFailed(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logUid = $logRepo->log(['crdate' => time(), 'configuration_uid' => 1]);

        $context = new RequestContext();
        $context->logUid = $logUid;
        $middleware = new GraderMiddleware($this->buildStubGradingService(), $logRepo, new NullLogger());

        $config = $this->buildConfiguration([
            'grading_enabled' => 1,
            'judge_configuration_uid' => 99,
        ]);
        $request = $this->buildConversationRequest($config);
        $failedResponse = new TextResponse('', errors: ['boom']);
        $next = new AiMiddlewareHandler(static fn() => $failedResponse, $context);

        $middleware->process($request, $this->stubProvider(), $config, $next);

        $row = $logRepo->findByUid($logUid);
        self::assertSame(GradeStatus::None->value, $row['grade_status']);
    }

    #[Test]
    public function doesNotMarkPendingWhenJudgeIsSameConfiguration(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logUid = $logRepo->log(['crdate' => time(), 'configuration_uid' => 5]);

        $context = new RequestContext();
        $context->logUid = $logUid;
        $middleware = new GraderMiddleware($this->buildStubGradingService(), $logRepo, new NullLogger());

        $config = $this->buildConfiguration([
            'uid' => 5,
            'grading_enabled' => 1,
            'judge_configuration_uid' => 5,
        ]);
        $request = $this->buildConversationRequest($config);
        $next = new AiMiddlewareHandler(static fn() => new TextResponse('ok'), $context);

        $middleware->process($request, $this->stubProvider(), $config, $next);

        $row = $logRepo->findByUid($logUid);
        self::assertSame(GradeStatus::None->value, $row['grade_status']);
    }

    private function buildConfiguration(array $overrides): ProviderConfiguration
    {
        $base = [
            'uid' => 1,
            'ai_provider' => 'openai',
            'title' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o-mini',
            'privacy_level' => 'standard',
            'grading_enabled' => 0,
            'judge_configuration_uid' => 0,
            'grading_rubric' => '',
        ];
        return new ProviderConfiguration(array_merge($base, $overrides));
    }

    private function buildConversationRequest(ProviderConfiguration $config): ConversationRequest
    {
        return new ConversationRequest(
            configuration: $config,
            messages: [new UserMessage('hi')],
        );
    }

    private function stubProvider(): AiProviderInterface
    {
        return new class implements AiProviderInterface {};
    }

    /**
     * A no-op grading service so register_shutdown_function doesn't actually
     * try to dispatch a judge request at end of test.
     */
    private function buildStubGradingService(): GradingService
    {
        return new class extends GradingService {
            // @phpstan-ignore-next-line — overriding constructor on purpose
            public function __construct() {}

            public function grade(int $logUid): void {}
        };
    }
}
