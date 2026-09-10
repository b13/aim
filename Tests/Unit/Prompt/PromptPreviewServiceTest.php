<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Prompt;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Prompt\PagePromptResolver;
use B13\Aim\Prompt\PromptFragmentRegistry;
use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Governance\ConfigurationAccess;
use B13\Aim\Prompt\PromptPreviewService;
use B13\Aim\Prompt\UserPromptFragmentResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class PromptPreviewServiceTest extends TestCase
{
    /**
     * @return array{0: PromptPreviewService, 1: PagePromptResolver&MockObject, 2: UserPromptFragmentResolver&MockObject, 3: PromptFragmentRegistry&MockObject, 4: ProviderConfigurationRepository&MockObject, 5: ExtensionConfiguration&MockObject, 6: LoggerInterface&MockObject}
     */
    private function createService(): array
    {
        $pageResolver = $this->createMock(PagePromptResolver::class);
        $userFragmentResolver = $this->createMock(UserPromptFragmentResolver::class);
        $registry = $this->createMock(PromptFragmentRegistry::class);
        $configurationRepository = $this->createMock(ProviderConfigurationRepository::class);
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new PromptPreviewService($pageResolver, $userFragmentResolver, $registry, $configurationRepository, new ConfigurationAccess(), $extensionConfiguration, $logger);
        return [$service, $pageResolver, $userFragmentResolver, $registry, $configurationRepository, $extensionConfiguration, $logger];
    }

    /**
     * The preview exists to show what the model actually receives, so a layer
     * that could not be read must be distinguishable from one that is simply
     * empty. Returning an empty tone would read as "none is configured", which
     * is a wrong answer presented as a correct one. TonePromptCompositionMiddleware
     * deliberately does the opposite and drops the tone to keep the request alive.
     */
    #[Test]
    public function aLayerThatCouldNotBeReadIsMarkedRatherThanShownAsEmpty(): void
    {
        [$service, $pageResolver, $userFragmentResolver, $registry, , , $logger] = $this->createService();
        $pageResolver->method('resolve')->willThrowException(
            new \RuntimeException("Unknown column 'tx_aim_page_prompt_fragment.hidden'")
        );
        $userFragmentResolver->method('getFragments')->willReturn(['Assigned to this editor.']);
        $registry->method('getFragments')->willReturn([]);
        $logger->expects(self::once())->method('error');

        $result = $service->preview(5, PromptFragmentScope::Text);

        $tone = $result['layers'][0];
        self::assertSame('pageTone', $tone['labelKey']);
        self::assertTrue($tone['unavailable'], 'The failure was not carried out to the caller.');

        // An empty layer is a different thing and must not be confused with it.
        $registryLayer = $result['layers'][2];
        self::assertSame([], $registryLayer['parts']);
        self::assertFalse($registryLayer['unavailable']);

        // Everything else still composed, so the preview is still useful.
        self::assertStringContainsString('Assigned to this editor.', $result['composed']);
    }

    #[Test]
    public function composesPageToneUserFragmentsRegistryFragmentsAndAddendumInOrder(): void
    {
        [$service, $pageResolver, $userFragmentResolver, $registry, $configurationRepository] = $this->createService();
        $pageResolver->method('resolve')->with(5, PromptFragmentScope::Text)->willReturn('Formal, third-person tone.');
        $userFragmentResolver->method('getFragments')->with(PromptFragmentScope::Text)->willReturn(['Assigned to this editor.']);
        $registry->method('getFragments')->with(PromptFragmentScope::Text)->willReturn(['Never use exclamation marks.']);
        $configurationRepository->method('findByUid')->with(1)->willReturn(new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'model' => 'gpt-4o',
            'system_prompt_addition' => 'Keep responses under 100 words.',
        ]));

        $result = $service->preview(5, PromptFragmentScope::Text, 1);

        self::assertSame(
            [
                ['labelKey' => 'pageTone', 'parts' => ['Formal, third-person tone.'], 'characterCount' => strlen('Formal, third-person tone.'), 'unavailable' => false],
                ['labelKey' => 'userFragments', 'parts' => ['Assigned to this editor.'], 'characterCount' => strlen('Assigned to this editor.'), 'unavailable' => false],
                ['labelKey' => 'registryFragments', 'parts' => ['Never use exclamation marks.'], 'characterCount' => strlen('Never use exclamation marks.'), 'unavailable' => false],
                ['labelKey' => 'providerAddendum', 'parts' => ['Keep responses under 100 words.'], 'characterCount' => strlen('Keep responses under 100 words.'), 'unavailable' => false],
            ],
            $result['layers'],
        );
        self::assertSame(
            "Formal, third-person tone.\n\nAssigned to this editor.\n\nNever use exclamation marks.\n\nKeep responses under 100 words.",
            $result['composed'],
        );
        self::assertSame(strlen($result['composed']), $result['totalCharacterCount']);
        self::assertSame((int)ceil(strlen($result['composed']) / 4), $result['approximateTokenCount']);
    }

    #[Test]
    public function fallsBackToGlobalDefaultAndLabelsItAccordinglyWhenNoPageIsSelected(): void
    {
        [$service, $pageResolver, , , , $extensionConfiguration] = $this->createService();
        $pageResolver->expects(self::never())->method('resolve');
        $extensionConfiguration->method('get')->with('aim', 'defaultSystemPrompt')->willReturn('Global fallback tone.');

        $result = $service->preview(null, PromptFragmentScope::Text);

        self::assertSame('globalFallback', $result['layers'][0]['labelKey']);
        self::assertSame(['Global fallback tone.'], $result['layers'][0]['parts']);
    }

    #[Test]
    public function omitsTheAddendumLayerEntirelyWhenNoProviderIsSelected(): void
    {
        [$service, $pageResolver, $userFragmentResolver, $registry] = $this->createService();
        $pageResolver->method('resolve')->willReturn('Tone.');
        $userFragmentResolver->method('getFragments')->willReturn([]);
        $registry->method('getFragments')->willReturn([]);

        $result = $service->preview(5, PromptFragmentScope::All);

        self::assertCount(3, $result['layers']);
        self::assertSame(['pageTone', 'userFragments', 'registryFragments'], array_column($result['layers'], 'labelKey'));
    }

    #[Test]
    public function showsAnEmptyAddendumLayerWhenTheSelectedProviderConfigurationDoesNotResolve(): void
    {
        [$service, $pageResolver, $userFragmentResolver, $registry, $configurationRepository] = $this->createService();
        $pageResolver->method('resolve')->willReturn('Tone.');
        $userFragmentResolver->method('getFragments')->willReturn([]);
        $registry->method('getFragments')->willReturn([]);
        $configurationRepository->method('findByUid')->with(999)->willReturn(null);

        $result = $service->preview(5, PromptFragmentScope::All, 999);

        self::assertCount(4, $result['layers']);
        self::assertSame('providerAddendum', $result['layers'][3]['labelKey']);
        self::assertSame([], $result['layers'][3]['parts']);
    }

    #[Test]
    public function suppressesTheAddendumWhenItExactlyDuplicatesAPartAlreadyCollectedFromEarlierLayers(): void
    {
        // Mirrors ProviderAddendumMiddleware's own duplicate-suppression,
        // otherwise the preview would show an addendum layer whose content
        // then mysteriously vanishes from `composed` once deduped, which
        // doesn't match what production actually sends in this situation.
        [$service, $pageResolver, $userFragmentResolver, $registry, $configurationRepository] = $this->createService();
        $pageResolver->method('resolve')->willReturn('Never use exclamation marks.');
        $userFragmentResolver->method('getFragments')->willReturn([]);
        $registry->method('getFragments')->willReturn([]);
        $configurationRepository->method('findByUid')->with(1)->willReturn(new ProviderConfiguration([
            'uid' => 1,
            'ai_provider' => 'openai',
            'model' => 'gpt-4o',
            'system_prompt_addition' => 'Never use exclamation marks.',
        ]));

        $result = $service->preview(5, PromptFragmentScope::All, 1);

        self::assertSame([], $result['layers'][3]['parts']);
        self::assertSame('Never use exclamation marks.', $result['composed']);
    }

    /**
     * The Prompt Management module is access => 'user', while
     * tx_aim_configuration is adminOnly and hidden from the record list, so
     * its system prompt addition is admin-authored text an editor has no other
     * way to read. The preview took any configuration uid it was handed, so
     * iterating uids returned each one's addition.
     */
    #[Test]
    public function theProviderAdditionStaysHiddenFromAUserWhoMayNotUseThatConfiguration(): void
    {
        [$service, $pageResolver, $userFragmentResolver, $registry, $configurationRepository] = $this->createService();
        $pageResolver->method('resolve')->willReturn(null);
        $userFragmentResolver->method('getFragments')->willReturn([]);
        $registry->method('getFragments')->willReturn([]);
        $configurationRepository->method('findByUid')->willReturn(new ProviderConfiguration([
            'uid' => 7,
            'ai_provider' => 'openai',
            'model' => 'gpt-4o',
            'be_groups' => '99',
            'system_prompt_addition' => 'Internal: mention the Q4 embargo.',
        ]));

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user = ['uid' => 5];
        $user->userGroupsUID = [1];
        $GLOBALS['BE_USER'] = $user;

        try {
            $result = $service->preview(5, PromptFragmentScope::Text, 7);
        } finally {
            unset($GLOBALS['BE_USER']);
        }

        self::assertStringNotContainsString('Q4 embargo', $result['composed']);
        $addendum = $result['layers'][3];
        self::assertSame('providerAddendum', $addendum['labelKey']);
        self::assertSame([], $addendum['parts']);
    }

    #[Test]
    public function theProviderAdditionIsShownToAUserWhoMayUseTheConfiguration(): void
    {
        [$service, $pageResolver, $userFragmentResolver, $registry, $configurationRepository] = $this->createService();
        $pageResolver->method('resolve')->willReturn(null);
        $userFragmentResolver->method('getFragments')->willReturn([]);
        $registry->method('getFragments')->willReturn([]);
        $configurationRepository->method('findByUid')->willReturn(new ProviderConfiguration([
            'uid' => 7,
            'ai_provider' => 'openai',
            'model' => 'gpt-4o',
            'be_groups' => '1',
            'system_prompt_addition' => 'Internal: mention the Q4 embargo.',
        ]));

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user = ['uid' => 5];
        $user->userGroupsUID = [1];
        $GLOBALS['BE_USER'] = $user;

        try {
            $result = $service->preview(5, PromptFragmentScope::Text, 7);
        } finally {
            unset($GLOBALS['BE_USER']);
        }

        self::assertStringContainsString('Q4 embargo', $result['composed']);
    }
}
