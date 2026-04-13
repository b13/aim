<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tca\ItemsProcFunc;

use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Registry\DisabledModelRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

#[Autoconfigure(public: true)]
class AiProvidersItemsProcFunc
{
    public function __construct(
        private readonly AiProviderRegistry $aiProviderRegistry,
        private readonly DisabledModelRegistry $disabledModelRegistry,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function getAiProviders(&$fieldDefinition): void
    {
        $lang = $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser());
        foreach ($this->aiProviderRegistry->getProviders() as $identifier => $provider) {
            $name = str_starts_with($provider->name, 'LLL:') ? $lang->sL($provider->name) : $provider->name;
            $description = str_starts_with($provider->description, 'LLL:') ? $lang->sL($provider->description) : $provider->description;
            $fieldDefinition['items'][] = [
                'label' => $name ?: $identifier,
                'value' => $identifier,
                'icon' => $provider->iconIdentifier,
                'description' => $description,
            ];
        }
    }

    public function getAiProviderModels(&$fieldDefinition): void
    {
        if (!isset($fieldDefinition['row']['ai_provider'])) {
            return;
        }
        $aiProviderIdentifier = is_array($fieldDefinition['row']['ai_provider'])
            ? ($fieldDefinition['row']['ai_provider'][0] ?? '')
            : $fieldDefinition['row']['ai_provider'];

        if (!$this->aiProviderRegistry->hasProvider($aiProviderIdentifier)) {
            return;
        }

        $lang = $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser());
        $provider = $this->aiProviderRegistry->getProvider($aiProviderIdentifier);

        foreach ($provider->supportedModels as $modelId => $description) {
            if ($this->disabledModelRegistry->isDisabled($aiProviderIdentifier, $modelId)) {
                continue;
            }
            $translatedDesc = str_starts_with($description, 'LLL:') ? $lang->sL($description) : $description;
            $label = $translatedDesc !== '' ? $modelId . ' (' . $translatedDesc . ')' : $modelId;
            $fieldDefinition['items'][] = [
                'label' => $label,
                'value' => $modelId,
            ];
        }
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
