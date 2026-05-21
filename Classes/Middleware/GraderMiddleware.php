<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Middleware;

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Governance\PrivacyLevel;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use B13\Aim\Service\GradingService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Schedules LLM-as-a-judge grading after a successful AI response.
 *
 * Priority -600: runs immediately outside of RequestLoggingMiddleware (-700),
 * so by the time process() resumes after the chain unwinds, the log row has
 * been inserted and $context->logUid is populated.
 *
 * The middleware never grades inline. It marks the row as pending and
 * defers the actual judge call via register_shutdown_function. The response
 * is therefore returned to the caller before any grading happens, keeping
 * live-request latency unchanged. A scheduler command (aim:grade-pending)
 * picks up rows the shutdown handler missed.
 */
#[AsAiMiddleware(priority: -600)]
final class GraderMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly GradingService $gradingService,
        private readonly RequestLogRepository $logRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $response = $next->handle($request, $provider, $configuration);

        if (!$this->shouldGrade($request, $response, $configuration, $next->context)) {
            return $response;
        }

        $logUid = (int)$next->context->logUid;
        try {
            $this->logRepository->markGradePending($logUid);
        } catch (\Throwable $e) {
            $this->logger->warning('AiM: failed to mark grade pending for log ' . $logUid . ': ' . $e->getMessage());
            return $response;
        }

        $gradingService = $this->gradingService;
        register_shutdown_function(static function () use ($gradingService, $logUid): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                $gradingService->grade($logUid);
            } catch (\Throwable) {
            }
        });

        return $response;
    }

    private function shouldGrade(
        AiRequestInterface $request,
        TextResponse $response,
        ProviderConfiguration $configuration,
        RequestContext $context,
    ): bool {
        if (!$configuration->gradingEnabled) {
            return false;
        }
        if ($configuration->judgeConfigurationUid <= 0
            || $configuration->judgeConfigurationUid === $configuration->uid
        ) {
            return false;
        }
        if (!$response->isSuccessful()) {
            return false;
        }
        if (!($request instanceof ConversationRequest) && !($request instanceof TextGenerationRequest)) {
            return false;
        }
        if (($request->metadata['_aim_grading'] ?? false) === true) {
            return false;
        }
        if ($context->logUid === null || $context->logUid <= 0) {
            return false;
        }
        if ($this->resolvePrivacyLevel($configuration, $request) !== PrivacyLevel::Standard) {
            return false;
        }
        return true;
    }

    /**
     * Mirror of RequestLoggingMiddleware::resolvePrivacyLevel. Kept inline rather
     * than extracted to avoid premature abstraction — there are only two callers.
     */
    private function resolvePrivacyLevel(ProviderConfiguration $configuration, AiRequestInterface $request): PrivacyLevel
    {
        $level = PrivacyLevel::fromString($configuration->privacyLevel);

        $user = $this->getBackendUser();
        if ($user !== null && method_exists($user, 'getTSConfig')) {
            $userLevel = PrivacyLevel::fromString(
                (string)($user->getTSConfig()['aim.']['privacyLevel'] ?? 'standard')
            );
            $level = $level->strictest($userLevel);
        }

        $requestOverride = $request->getPrivacyLevelOverride();
        if ($requestOverride !== null) {
            $level = $level->strictest($requestOverride);
        }

        return $level;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
