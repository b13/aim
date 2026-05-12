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

use B13\Aim\Provider\LiveModelDiscovery;
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
        private readonly LiveModelDiscovery $liveModelDiscovery,
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

        // No static catalog (Ollama, LM Studio, …) — when the record's api_key
        // points at an HTTP endpoint, query the live server for its models.
        // Currently understands the Ollama-compatible /api/tags shape.
        if ($provider->supportedModels === []) {
            $this->appendLiveModels($fieldDefinition, $aiProviderIdentifier);
        }
    }

    private function appendLiveModels(array &$fieldDefinition, string $providerIdentifier): void
    {
        $endpoint = (string)($fieldDefinition['row']['api_key'] ?? '');
        foreach ($this->liveModelDiscovery->fetchModelNames($endpoint) as $name) {
            if ($this->disabledModelRegistry->isDisabled($providerIdentifier, $name)) {
                continue;
            }
            // Value stays intact (Ollama needs the full "model:tag" string).
            // Label is colon-free so it bypasses TYPO3 v14's LanguageService::sL(),
            // which treats any colon-bearing string as a "domain:key" reference
            // and tries to look up "domain" as a TYPO3 package.
            $fieldDefinition['items'][] = [
                'label' => str_replace(':', ' / ', $name),
                'value' => $name,
            ];
        }
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
