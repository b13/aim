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

use B13\Aim\Backend\Form\FieldControl\ClearApiKey;
use B13\Aim\Backend\FormDataProvider\HideApiKey;
use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Hooks\EncryptApiKey;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The control is wired through three separate places: a nodeRegistry entry, a
 * renderType name in TCA, and a JavaScript module path. A typo in any of them
 * fails silently, the button simply never appears, and then a stored key cannot
 * be removed. This is the same shape of mistake as the HideApiKey ordering bug,
 * which also broke nothing visibly.
 */
final class ClearApiKeyControlTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function theRenderTypeResolvesToTheControl(): void
    {
        $node = GeneralUtility::makeInstance(NodeFactory::class)->create([
            'renderType' => 'aimClearApiKey',
            'tableName' => 'tx_aim_configuration',
            'fieldName' => 'api_key',
        ]);

        self::assertInstanceOf(ClearApiKey::class, $node);
    }

    #[Test]
    public function theApiKeyFieldDeclaresTheControl(): void
    {
        $config = $GLOBALS['TCA']['tx_aim_configuration']['columns']['api_key']['config'] ?? [];

        self::assertSame('password', $config['type'] ?? null);
        self::assertSame(
            'aimClearApiKey',
            $config['fieldControl']['aimClearApiKey']['renderType'] ?? null,
            'The button is not declared on the field it is meant to sit next to.',
        );
    }

    /**
     * The JavaScript needs the field's form element name to find the input, and
     * the marker to write into it. Without either the button does nothing.
     */
    #[Test]
    public function theControlPassesWhatItsJavaScriptNeeds(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $node = GeneralUtility::makeInstance(NodeFactory::class)->create([
            'renderType' => 'aimClearApiKey',
            'tableName' => 'tx_aim_configuration',
            'fieldName' => 'api_key',
            'parameterArray' => ['itemFormElName' => 'data[tx_aim_configuration][1][api_key]'],
        ]);
        $result = $node->render();

        self::assertSame(
            'data[tx_aim_configuration][1][api_key]',
            $result['linkAttributes']['data-item-name'] ?? null,
        );
        self::assertSame(EncryptApiKey::CLEAR_MARKER, $result['linkAttributes']['data-marker'] ?? null);
        self::assertNotSame('', $result['iconIdentifier'] ?? '');

        // FieldControl puts the returned title on the icon only, so without
        // these the button has no tooltip and no accessible name at all.
        foreach (['title', 'aria-label'] as $attribute) {
            $value = (string)($result['linkAttributes'][$attribute] ?? '');
            self::assertNotSame('', $value, sprintf('The button has no %s.', $attribute));
            self::assertStringNotContainsString('LLL:', $value, sprintf('The %s is an unresolved label key.', $attribute));
        }

        // Core renders every field control as <a class="btn btn-default"
        // href="#">, so without these a screen reader announces a link, and
        // the two-state nature of the control is not conveyed at all.
        self::assertSame('button', $result['linkAttributes']['role'] ?? null, 'The control is announced as a link.');
        self::assertSame(
            'false',
            $result['linkAttributes']['aria-pressed'] ?? null,
            'The control starts out without a pressed state, so nothing conveys armed versus idle.',
        );

        // Everything the JavaScript reads for the armed state. A missing one
        // fails silently: the button works but explains nothing.
        foreach (['data-icon-armed', 'data-label-armed', 'data-label-idle', 'data-label-pending', 'data-placeholder-pending'] as $attribute) {
            $value = (string)($result['linkAttributes'][$attribute] ?? '');
            self::assertNotSame('', $value, sprintf('%s is empty.', $attribute));
            self::assertStringNotContainsString('LLL:', $value, sprintf('%s is an unresolved label key.', $attribute));
        }

        $modules = $result['javaScriptModules'] ?? [];
        self::assertCount(1, $modules);
        self::assertStringContainsString('clear-api-key.js', (string)$modules[0]->getName());
    }

    /**
     * The control is only meaningful where a key is actually stored. HideApiKey
     * drops it from the processed TCA otherwise, and FormEngine renders only
     * the controls it is handed (AbstractFormElement reads them from
     * parameterArray.fieldConf.config.fieldControl, which SingleFieldContainer
     * copies out of processedTca). Runs against the real TCA rather than a
     * handcrafted array, so a wrong key path in the provider - which would
     * silently leave the button in place - fails here.
     */
    #[Test]
    public function theControlIsOfferedOnlyWhenThereIsAKeyToClear(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $encryption = new ApiKeyEncryption();
        $provider = new HideApiKey($encryption);
        $realTca = $GLOBALS['TCA']['tx_aim_configuration'];
        self::assertArrayHasKey(
            'aimClearApiKey',
            $realTca['columns']['api_key']['config']['fieldControl'],
            'Precondition: TCA declares the control unconditionally.',
        );

        $withoutKey = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => '', 'endpoint' => ''],
            'processedTca' => $realTca,
        ]);
        $withKey = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => $encryption->encrypt('sk-stored'), 'endpoint' => ''],
            'processedTca' => $realTca,
        ]);

        self::assertArrayNotHasKey(
            'aimClearApiKey',
            $withoutKey['processedTca']['columns']['api_key']['config']['fieldControl'],
            'A record with no stored key still offers to remove one.',
        );
        self::assertArrayHasKey(
            'aimClearApiKey',
            $withKey['processedTca']['columns']['api_key']['config']['fieldControl'],
            'A stored key can no longer be removed.',
        );
    }

    /**
     * The module path has to resolve through the extension's own import map, or
     * the browser silently 404s it.
     */
    #[Test]
    public function theJavaScriptFileExistsWhereTheImportMapPointsIt(): void
    {
        $imports = require __DIR__ . '/../../../Configuration/JavaScriptModules.php';
        $prefix = $imports['imports']['@b13/aim/'] ?? '';

        self::assertSame('EXT:aim/Resources/Public/JavaScript/', $prefix);
        self::assertFileExists(
            __DIR__ . '/../../../Resources/Public/JavaScript/field-control/clear-api-key.js',
            'The module the control references is not where the import map resolves it.',
        );
    }
}
