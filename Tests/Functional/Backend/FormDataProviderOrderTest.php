<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Backend;

use B13\Aim\Backend\FormDataProvider\HideApiKey;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectItems;
use TYPO3\CMS\Core\Service\DependencyOrderingService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * HideApiKey blanks api_key so a stored credential never reaches the browser.
 * The model itemsProcFunc runs from TcaSelectItems and reads api_key to
 * authenticate model discovery, so the two have an ordering contract: declaring
 * only a dependency on DatabaseEditRow leaves HideApiKey free to sort after
 * TcaSelectItems, and the itemsProcFunc then sees the raw ciphertext.
 */
final class FormDataProviderOrderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function hideApiKeyRunsBeforeSelectItemsAreResolved(): void
    {
        $providers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'] ?? [];
        self::assertArrayHasKey(HideApiKey::class, $providers, 'HideApiKey is not registered at all.');

        $ordered = array_keys(
            (new DependencyOrderingService())->orderByDependencies($providers, 'before', 'depends')
        );

        $hideApiKey = array_search(HideApiKey::class, $ordered, true);
        $selectItems = array_search(TcaSelectItems::class, $ordered, true);

        self::assertIsInt($hideApiKey);
        self::assertIsInt($selectItems);
        self::assertLessThan(
            $selectItems,
            $hideApiKey,
            sprintf(
                'HideApiKey (%d) must run before TcaSelectItems (%d), otherwise the model '
                . 'itemsProcFunc reads the encrypted api_key and sends the ciphertext to the endpoint.',
                $hideApiKey,
                $selectItems,
            ),
        );
    }
}
