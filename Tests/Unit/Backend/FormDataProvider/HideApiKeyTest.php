<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Backend\FormDataProvider;

use B13\Aim\Backend\FormDataProvider\HideApiKey;
use B13\Aim\Crypto\ApiKeyEncryption;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

final class HideApiKeyTest extends TestCase
{
    private ?LanguageService $originalLang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLang = $GLOBALS['LANG'] ?? null;
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        $GLOBALS['LANG'] = $this->originalLang;
        parent::tearDown();
    }

    #[Test]
    public function blanksAStoredKeySoItNeverReachesTheDom(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $result = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => 'aim:enc:v2:whatever'],
            'processedTca' => ['columns' => ['api_key' => ['config' => ['type' => 'password']]]],
        ]);

        self::assertSame('', $result['databaseRow']['api_key']);
        self::assertStringContainsString('placeholder.configured', $result['processedTca']['columns']['api_key']['config']['placeholder']);
    }

    /**
     * Masking is the TCA's job, so this no longer checks for ciphertext.
     */
    #[Test]
    public function blanksALegacyPlaintextKeyToo(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $result = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => 'sk-legacy-plaintext-key'],
            'processedTca' => ['columns' => ['api_key' => ['config' => ['type' => 'password']]]],
        ]);

        self::assertSame('', $result['databaseRow']['api_key']);
    }

    /**
     * Before the split migration runs, an endpoint URL still lives in api_key
     * while the endpoint column is empty. Blanking api_key without surfacing it
     * leaves the URL nowhere to be seen, which reads as data loss.
     */
    #[Test]
    public function aLegacyEndpointUrlIsShownInTheEndpointField(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $result = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => 'http://host.docker.internal:11434', 'endpoint' => ''],
            'processedTca' => ['columns' => [
                'api_key' => ['config' => ['type' => 'password']],
                'endpoint' => ['config' => ['type' => 'input']],
            ]],
        ]);

        self::assertSame('http://host.docker.internal:11434', $result['databaseRow']['endpoint']);
        self::assertSame('', $result['databaseRow']['api_key']);
        self::assertArrayNotHasKey('placeholder', $result['processedTca']['columns']['api_key']['config'], 'A URL is not a configured key.');
    }

    #[Test]
    public function amigratedEndpointIsNotOverwrittenByALeftoverApiKey(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $result = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => 'http://stale:11434', 'endpoint' => 'http://migrated:11434'],
            'processedTca' => ['columns' => [
                'api_key' => ['config' => ['type' => 'password']],
                'endpoint' => ['config' => ['type' => 'input']],
            ]],
        ]);

        self::assertSame('http://migrated:11434', $result['databaseRow']['endpoint']);
    }

    /**
     * ClearApiKey renders unconditionally, so without this the trash control
     * sits next to the empty password field of a new (or key-less) record and
     * promises to remove a key that does not exist. This provider is the one
     * place that already knows whether anything is stored.
     */
    #[Test]
    public function theClearControlIsRemovedWhenThereIsNoStoredKey(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $result = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => ''],
            'processedTca' => ['columns' => ['api_key' => ['config' => [
                'type' => 'password',
                'fieldControl' => ['aimClearApiKey' => ['renderType' => 'aimClearApiKey']],
            ]]]],
        ]);

        self::assertSame(
            [],
            $result['processedTca']['columns']['api_key']['config']['fieldControl'],
            'The control is offered although there is no key to clear.',
        );
    }

    #[Test]
    public function theClearControlSurvivesWhenAKeyIsStored(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $result = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => 'aim:enc:v2:whatever'],
            'processedTca' => ['columns' => ['api_key' => ['config' => [
                'type' => 'password',
                'fieldControl' => ['aimClearApiKey' => ['renderType' => 'aimClearApiKey']],
            ]]]],
        ]);

        self::assertArrayHasKey(
            'aimClearApiKey',
            $result['processedTca']['columns']['api_key']['config']['fieldControl'],
            'A stored key can no longer be removed.',
        );
    }

    #[Test]
    public function leavesAnEmptyFieldUntouched(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $input = [
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => ''],
            'processedTca' => ['columns' => ['api_key' => ['config' => ['type' => 'password']]]],
        ];

        $result = $provider->addData($input);

        self::assertSame($input, $result);
    }

    #[Test]
    public function ignoresOtherTables(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $input = [
            'tableName' => 'tt_content',
            'databaseRow' => ['api_key' => 'aim:enc:v2:should-not-be-touched'],
        ];

        $result = $provider->addData($input);

        self::assertSame($input, $result);
    }
}
