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

use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Provider\EndpointCredential;
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
        private readonly ProviderConfigurationRepository $configurationRepository,
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

        // No static catalog (Ollama, LM Studio, ...): when the record names an
        // HTTP endpoint, ask that server for its models. Reads the
        // OpenAI-compatible /v1/models shape.
        if ($provider->supportedModels === []) {
            $this->appendLiveModels($fieldDefinition, $aiProviderIdentifier);
        }
    }

    private function appendLiveModels(array &$fieldDefinition, string $providerIdentifier): void
    {
        $row = $fieldDefinition['row'] ?? [];
        $endpoint = $this->resolveDiscoveryEndpoint($row);
        $credential = $this->resolveDiscoveryCredential($row);

        // A host doing basic auth wants the credential in the URL and rejects a
        // bearer token, so it goes to one place or the other, never both.
        if (EndpointCredential::expectsCredential($endpoint)) {
            $endpoint = EndpointCredential::merge($endpoint, $credential);
            $credential = '';
        }

        foreach ($this->liveModelDiscovery->fetchModelNames($endpoint, $credential) as $name) {
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

    private function resolveDiscoveryEndpoint(array $row): string
    {
        $endpoint = (string)($row['endpoint'] ?? '');
        if ($endpoint !== '') {
            return $endpoint;
        }

        $legacy = (string)($row['api_key'] ?? '');

        return $this->liveModelDiscovery->isHttpEndpoint($legacy) ? $legacy : '';
    }

    /**
     * A host may want a bearer token for its model list. HideApiKey blanks the
     * form row's api_key before this runs, which its registration in
     * ext_localconf.php guarantees by ordering itself before TcaSelectItems, so
     * the stored credential is read back from the record and decrypted here. A
     * freshly typed one is still in the row and wins.
     *
     * @param array<string, mixed> $row
     */
    private function resolveDiscoveryCredential(array $row): string
    {
        $typed = (string)($row['api_key'] ?? '');
        if ($typed !== '') {
            return $typed;
        }

        $uid = (int)($row['uid'] ?? 0);
        if ($uid <= 0) {
            // A record that has never been saved has no stored credential.
            return '';
        }

        return $this->configurationRepository->findByUid($uid)?->apiKey ?? '';
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
