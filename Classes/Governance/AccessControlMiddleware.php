<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Governance;

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Middleware\AiMiddlewareHandler;
use B13\Aim\Middleware\AiMiddlewareInterface;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\TextResponse;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Enforces access control, budgets, and rate limits for AI requests.
 *
 * Runs at priority 90 — after RetryWithFallback (100) but before
 * SmartRouting (75). All governance checks happen here before any
 * provider interaction.
 *
 * Checks in order:
 * 1. No user context (CLI/frontend — pass through)
 * 2. Provider group restriction (be_groups on configuration) — admins skip
 * 3. Capability permission (customPermOptions) — admins skip
 * 4. Budget limit (TSconfig aim.budget.*) — applies to ALL users including admins
 * 5. Rate limit (TSconfig aim.rateLimit.*) — applies to ALL users including admins
 *
 * Budgets and rate limits apply to admins as a safety net against
 * accidental cost overruns. Admins can configure their own limits
 * via UserTSconfig and will be blocked when exceeded.
 */
#[AsAiMiddleware(priority: 90)]
final class AccessControlMiddleware implements AiMiddlewareInterface
{
    /**
     * Maps request types to custom permission option keys.
     */
    private const CAPABILITY_PERMISSIONS = [
        VisionRequest::class => 'aim:capability_vision',
        TextGenerationRequest::class => 'aim:capability_text',
        TranslationRequest::class => 'aim:capability_translation',
        ConversationRequest::class => 'aim:capability_conversation',
        EmbeddingRequest::class => 'aim:capability_embedding',
        ToolCallingRequest::class => 'aim:capability_toolcalling',
    ];

    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly RequestLogRepository $logRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $user = $this->getBackendUser();

        // No user context (CLI, frontend, etc.) — pass through
        if ($user === null) {
            return $next->handle($request, $provider, $configuration);
        }

        $isAdmin = $user->isAdmin();

        // Access control checks — admins skip these
        if (!$isAdmin) {
            // 1. Provider group restriction
            $denied = $this->checkProviderAccess($configuration, $user);
            if ($denied !== null) {
                return $denied;
            }

            // 2. Capability permission
            $denied = $this->checkCapabilityPermission($request, $user);
            if ($denied !== null) {
                return $denied;
            }
        }

        // Safety net checks — apply to ALL users including admins
        // 3. Budget limit
        $denied = $this->checkBudget($user);
        if ($denied !== null) {
            return $denied;
        }

        // 4. Rate limit
        $denied = $this->checkRateLimit($user);
        if ($denied !== null) {
            return $denied;
        }

        return $next->handle($request, $provider, $configuration);
    }

    private function checkProviderAccess(ProviderConfiguration $configuration, BackendUserAuthentication $user): ?TextResponse
    {
        $allowedGroups = trim((string)$configuration->get('be_groups', ''));
        if ($allowedGroups === '') {
            return null;
        }

        $allowedGroupIds = array_map('intval', explode(',', $allowedGroups));
        $userGroupIds = array_map('intval', $user->userGroupsUID ?? []);

        if (array_intersect($allowedGroupIds, $userGroupIds) === []) {
            $this->logger->warning(sprintf(
                'Access denied: user %d not in allowed groups [%s] for provider configuration "%s" (uid %d).',
                $user->user['uid'] ?? 0,
                $allowedGroups,
                $configuration->title,
                $configuration->uid,
            ));
            return new TextResponse('', errors: [
                'Access denied: you do not have permission to use this AI provider configuration.',
            ]);
        }

        return null;
    }

    private function checkCapabilityPermission(AiRequestInterface $request, BackendUserAuthentication $user): ?TextResponse
    {
        // Only enforce if the user's groups have any aim permissions configured.
        // If no aim permissions are set at all, all capabilities are allowed (permissive default).
        $customOptions = $user->groupData['custom_options'] ?? '';
        if (!str_contains($customOptions, 'aim:')) {
            return null;
        }

        $permissionKey = null;
        foreach (self::CAPABILITY_PERMISSIONS as $requestClass => $key) {
            if ($request instanceof $requestClass) {
                $permissionKey = $key;
                break;
            }
        }

        if ($permissionKey === null) {
            return null;
        }

        if (!$user->check('custom_options', $permissionKey)) {
            $capability = substr(strrchr($permissionKey, '_') ?: $permissionKey, 1);
            $this->logger->warning(sprintf(
                'Access denied: user %d lacks permission "%s".',
                $user->user['uid'] ?? 0,
                $permissionKey,
            ));
            return new TextResponse('', errors: [
                sprintf('Access denied: you do not have permission to use the %s capability.', $capability),
            ]);
        }

        return null;
    }

    private function checkBudget(BackendUserAuthentication $user): ?TextResponse
    {
        $userId = (int)($user->user['uid'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $tsConfig = $user->getTSConfig()['aim.']['budget.'] ?? [];
        if ($tsConfig === []) {
            return null;
        }

        // Flatten TSconfig dot notation: budget. → budget
        $budgetConfig = [];
        foreach ($tsConfig as $key => $value) {
            $budgetConfig[rtrim($key, '.')] = $value;
        }

        $result = $this->budgetService->checkBudget($userId, $budgetConfig);
        if (!$result->allowed) {
            $this->logger->warning(sprintf(
                'Budget exceeded for user %d: %s',
                $userId,
                $result->reason,
            ));
            return new TextResponse('', errors: [$result->reason]);
        }

        return null;
    }

    private function checkRateLimit(BackendUserAuthentication $user): ?TextResponse
    {
        $userId = (int)($user->user['uid'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $limit = (int)($user->getTSConfig()['aim.']['rateLimit.']['requestsPerMinute'] ?? 0);
        if ($limit <= 0) {
            return null;
        }

        $oneMinuteAgo = time() - 60;
        $recentCount = $this->logRepository->countRecentRequestsByUser($userId, $oneMinuteAgo);

        if ($recentCount >= $limit) {
            $this->logger->warning(sprintf(
                'Rate limit exceeded for user %d: %d requests in the last minute (limit: %d).',
                $userId,
                $recentCount,
                $limit,
            ));
            return new TextResponse('', errors: [
                sprintf('Rate limit exceeded: maximum %d requests per minute.', $limit),
            ]);
        }

        return null;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
