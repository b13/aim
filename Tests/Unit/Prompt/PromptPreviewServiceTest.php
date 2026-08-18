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
use B13\Aim\Prompt\PromptPreviewService;
use B13\Aim\Prompt\UserPromptFragmentResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class PromptPreviewServiceTest extends TestCase
{
    /**
     * @return array{0: PromptPreviewService, 1: PagePromptResolver&MockObject, 2: UserPromptFragmentResolver&MockObject, 3: PromptFragmentRegistry&MockObject, 4: ProviderConfigurationRepository&MockObject, 5: ExtensionConfiguration&MockObject}
     */
    private function createService(): array
    {
        $pageResolver = $this->createMock(PagePromptResolver::class);
        $userFragmentResolver = $this->createMock(UserPromptFragmentResolver::class);
        $registry = $this->createMock(PromptFragmentRegistry::class);
        $configurationRepository = $this->createMock(ProviderConfigurationRepository::class);
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);

        $service = new PromptPreviewService($pageResolver, $userFragmentResolver, $registry, $configurationRepository, $extensionConfiguration);
        return [$service, $pageResolver, $userFragmentResolver, $registry, $configurationRepository, $extensionConfiguration];
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
                ['labelKey' => 'pageTone', 'parts' => ['Formal, third-person tone.'], 'characterCount' => strlen('Formal, third-person tone.')],
                ['labelKey' => 'userFragments', 'parts' => ['Assigned to this editor.'], 'characterCount' => strlen('Assigned to this editor.')],
                ['labelKey' => 'registryFragments', 'parts' => ['Never use exclamation marks.'], 'characterCount' => strlen('Never use exclamation marks.')],
                ['labelKey' => 'providerAddendum', 'parts' => ['Keep responses under 100 words.'], 'characterCount' => strlen('Keep responses under 100 words.')],
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
}
