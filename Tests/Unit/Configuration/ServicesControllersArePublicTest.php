<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression test: #[AsController] (the attribute that would otherwise
 * make these three controllers public, so their module routes can resolve
 * them) doesn't exist yet on TYPO3 12.4.0-12.4.8 - core's own attribute
 * wiring for it is itself only registered from 12.4.9 on, so on that
 * narrow early window the attribute is silently inert and the controllers
 * would stay private under this file's own `_defaults: public: false`.
 * Explicit `public: true` entries here sidestep that entirely, independent
 * of whether the running version recognizes the attribute - this test
 * can't exercise that against a real v12.4.0-12.4.8 core (only v14.3.6 is
 * installed, see composer.lock), so it instead locks in the source-level
 * safeguard directly, the same way ModulesNavigationComponentSourceTest
 * does for its own untestable-in-this-environment branch.
 */
final class ServicesControllersArePublicTest extends TestCase
{
    #[Test]
    public function everyControllerIsExplicitlyPublicIndependentOfTheAsControllerAttribute(): void
    {
        $services = Yaml::parseFile(__DIR__ . '/../../../Configuration/Services.yaml')['services'];

        foreach (['B13\Aim\Controller\ProviderController', 'B13\Aim\Controller\RequestLogController', 'B13\Aim\Controller\PromptManagementController'] as $controller) {
            self::assertArrayHasKey($controller, $services, $controller . ' has no explicit Services.yaml entry at all.');
            self::assertTrue(
                $services[$controller]['public'] ?? false,
                $controller . ' must be explicitly public: true - relying on #[AsController] alone silently fails to publish it on TYPO3 12.4.0-12.4.8, where that attribute does not exist yet.',
            );
        }
    }
}
