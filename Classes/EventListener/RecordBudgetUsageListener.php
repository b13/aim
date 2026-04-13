<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\EventListener;

use B13\Aim\Event\AfterAiResponseEvent;
use B13\Aim\Governance\BudgetService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Records token/cost usage against the current user's budget after each AI response.
 */
#[AsEventListener('aim/record-budget-usage')]
final class RecordBudgetUsageListener
{
    public function __construct(
        private readonly BudgetService $budgetService,
    ) {}

    public function __invoke(AfterAiResponseEvent $event): void
    {
        $user = $this->getBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return;
        }

        $userId = (int)($user->user['uid'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $period = (string)($user->getTSConfig()['aim.']['budget.']['period'] ?? 'monthly');
        $response = $event->response;

        if (!$response->isSuccessful()) {
            return;
        }

        $this->budgetService->recordUsage(
            $userId,
            rtrim($period, '.'),
            $response->usage->getTotalTokens(),
            $response->usage->cost,
        );
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
