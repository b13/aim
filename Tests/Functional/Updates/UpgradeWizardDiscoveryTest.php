<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Updates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The wizards have to be reachable through the tagged service locator, not just
 * resolvable as classes. Resolving the class directly says nothing about
 * whether the Install Tool will ever list it.
 *
 * The registry moved from Install\Updates to Core\Upgrades in v14, so the class
 * is resolved at runtime rather than imported: an import would make this file
 * unloadable on v12 and v13, where the aim wizards use the Install\ namespace.
 */
final class UpgradeWizardDiscoveryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function registeredIdentifiers(): array
    {
        return [
            'encrypt api keys' => ['aimEncryptApiKeys'],
            'split endpoint from api key' => ['aimSplitEndpointFromApiKey'],
            'split rerouting direction flags' => ['aimSplitReroutingDirectionFlags'],
        ];
    }

    #[Test]
    #[DataProvider('registeredIdentifiers')]
    public function theWizardIsRegisteredUnderItsIdentifier(string $identifier): void
    {
        $registry = 'TYPO3\\CMS\\Core\\Upgrades\\UpgradeWizardRegistry';
        if (!class_exists($registry)) {
            $registry = 'TYPO3\\CMS\\Install\\Updates\\UpgradeWizardRegistry';
        }
        self::assertTrue(
            class_exists($registry),
            'Neither the v14 nor the v12/v13 upgrade wizard registry could be found.',
        );

        self::assertTrue(
            $this->get($registry)->hasUpgradeWizard($identifier),
            sprintf('Upgrade wizard "%s" is not in the tagged locator, so the Install Tool cannot list it.', $identifier),
        );
    }
}
