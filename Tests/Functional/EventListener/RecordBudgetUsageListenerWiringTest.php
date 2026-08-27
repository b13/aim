<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\EventListener;

use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Event\AfterAiResponseEvent;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression test: RecordBudgetUsageListener imported Symfony's own
 * Symfony\Component\EventDispatcher\Attribute\AsEventListener instead of
 * TYPO3's TYPO3\CMS\Core\Attribute\AsEventListener. TYPO3's container only
 * autoconfigures its own attribute class, so the listener was never
 * actually registered on any TYPO3 version - the class itself was correct
 * and would have passed a unit test in isolation, which is exactly why the
 * bug survived. This test drives the real, compiled event dispatcher
 * instead of calling the listener directly, so a future un-registration
 * (e.g. reverting to the wrong attribute) would fail this test even though
 * the listener class still works perfectly well on its own.
 */
final class RecordBudgetUsageListenerWiringTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function dispatchingAfterAiResponseEventRecordsUsageAgainstTheCurrentUsersBudget(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 5,
            'pid' => 0,
            'username' => 'budget-user',
            'admin' => 0,
            'TSconfig' => 'aim.budget.period = monthly',
        ]);
        $backendUser = $this->setUpBackendUser(5);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $configuration = new ProviderConfiguration(['ai_provider' => 'openai', 'title' => 'Test configuration']);
        $provider = new AiProviderManifest(
            identifier: 'openai',
            name: 'OpenAI',
            description: '',
            iconIdentifier: '',
            supportedModels: ['gpt-4o' => 'GPT-4o'],
            capabilities: [],
            serviceName: 'dummy',
            container: $this->get(ContainerInterface::class),
        );
        $request = new TranslationRequest($configuration, 'Hello', 'en', 'de');
        $response = new TextResponse('Hallo', new AiUsageStatistics(promptTokens: 10, completionTokens: 5, cost: 0.02));

        $this->get(EventDispatcherInterface::class)->dispatch(
            new AfterAiResponseEvent($response, $request, $provider, $configuration),
        );

        $usage = $this->getConnectionPool()->getConnectionForTable('tx_aim_usage_budget')
            ->select(['tokens_used', 'cost_used', 'requests_used'], 'tx_aim_usage_budget', ['user_id' => 5, 'period_type' => 'monthly'])
            ->fetchAssociative();

        self::assertNotFalse($usage, 'No usage row was recorded at all - the listener never ran.');
        self::assertSame(15, (int)$usage['tokens_used']);
        self::assertEqualsWithDelta(0.02, (float)$usage['cost_used'], 0.0001);
        self::assertSame(1, (int)$usage['requests_used']);
    }
}
