<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Domain\Model;

use B13\Aim\Domain\Model\ProviderConfigurationFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Site settings are the one way into a provider configuration that never passes
 * through DataHandler, so nothing was taking an inline credential out of the
 * endpoint there.
 *
 * Real Site and SiteSettings objects rather than mocks: both are final, and
 * going through the actual resolution is what makes the dotted keys meaningful.
 */
final class SiteSettingsConfigurationTest extends TestCase
{
    /**
     * With a password left in the URL, expectsCredentialInUrl() is false, so
     * the "either the URL or a header, never both" rule does not engage and the
     * host receives two credentials.
     */
    #[Test]
    public function aCredentialInTheEndpointIsTakenOutOfIt(): void
    {
        $config = ProviderConfigurationFactory::fromSiteSettings($this->site([
            'ai' => ['provider' => 'ollama', 'endpoint' => 'https://svc:url-password@gw.example.com/v1'],
        ]));

        self::assertNotNull($config);
        self::assertSame('https://svc@gw.example.com/v1', $config->endpoint);
        self::assertSame('url-password', $config->apiKey);
        // The user half is what marks it as belonging in the URL.
        self::assertTrue($config->expectsCredentialInUrl());
        self::assertSame('https://svc:url-password@gw.example.com/v1', $config->getRequestEndpoint());
    }

    /**
     * Same precedence as the DataHandler hook: the setting meant for a
     * credential wins, and the URL's password is dropped rather than sent
     * alongside it.
     */
    #[Test]
    public function anExplicitApiKeySettingWinsOverOneInTheUrl(): void
    {
        $config = @ProviderConfigurationFactory::fromSiteSettings($this->site([
            'ai' => [
                'provider' => 'ollama',
                'endpoint' => 'https://svc:url-password@gw.example.com/v1',
                'apiKey' => 'sk-explicit',
            ],
        ]));

        self::assertNotNull($config);
        self::assertSame('https://svc@gw.example.com/v1', $config->endpoint);
        self::assertSame('sk-explicit', $config->apiKey);
        self::assertStringNotContainsString('url-password', $config->getRequestEndpoint());
    }

    #[Test]
    public function aConflictBetweenTheTwoIsReported(): void
    {
        $reported = null;
        set_error_handler(static function (int $severity, string $message) use (&$reported): bool {
            $reported = $message;

            return true;
        }, E_USER_WARNING);

        try {
            ProviderConfigurationFactory::fromSiteSettings($this->site([
                'ai' => [
                    'provider' => 'ollama',
                    'endpoint' => 'https://svc:url-password@gw.example.com/v1',
                    'apiKey' => 'sk-explicit',
                ],
            ]), 'ai');
        } finally {
            restore_error_handler();
        }

        self::assertIsString($reported);
        self::assertStringContainsString('ai.endpoint', $reported);
        self::assertStringContainsString('ai.apiKey', $reported);
        // The message must not quote either secret.
        self::assertStringNotContainsString('url-password', $reported);
        self::assertStringNotContainsString('sk-explicit', $reported);
    }

    /**
     * The documented YAML is a nested tree (`ai:`, then `provider:`), which is
     * the shape TYPO3 hands over. On 12.4 SiteSettings::has('ai.provider')
     * answers false for it, because has() only looks at the unflattened top
     * level of the tree, so the lookup has to read through get() to find a
     * site-configured provider at all.
     */
    #[Test]
    public function aProviderConfiguredAsANestedSettingsTreeIsFound(): void
    {
        $config = ProviderConfigurationFactory::fromSiteSettings($this->site([
            'ai' => [
                'provider' => 'ollama',
                'endpoint' => 'http://localhost:11434',
                'model' => 'llama3',
            ],
        ]));

        self::assertNotNull($config, 'A provider configured as a nested settings tree must be found.');
        self::assertSame('ollama', $config->providerIdentifier);
        self::assertSame('http://localhost:11434', $config->endpoint);
        self::assertSame('llama3', $config->model);
    }

    #[Test]
    public function anEndpointWithoutACredentialIsUntouched(): void
    {
        $config = ProviderConfigurationFactory::fromSiteSettings($this->site([
            'ai' => ['provider' => 'ollama', 'endpoint' => 'http://localhost:11434', 'apiKey' => 'sk-token'],
        ]));

        self::assertNotNull($config);
        self::assertSame('http://localhost:11434', $config->endpoint);
        self::assertSame('sk-token', $config->apiKey);
        self::assertFalse($config->expectsCredentialInUrl(), 'No user in the URL means the header path.');
    }

    /**
     * The nested settings tree, exactly as a site's config.yaml carries it and
     * as README.md documents it.
     *
     * Handed over in the configuration rather than as a prebuilt SiteSettings,
     * because building that object differs per version: 12.4 takes the tree in
     * its constructor, 13.4 and 14 have SiteSettings::createFromSettingsTree().
     * The Site itself picks the right one, which is also the production path.
     *
     * @param array<string, mixed> $settings
     */
    private function site(array $settings): Site
    {
        return new Site('probe', 1, ['base' => 'https://example.org/', 'settings' => $settings]);
    }
}
