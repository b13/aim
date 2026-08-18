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
    public function blanksAnEncryptedKeyAndSwitchesToAPasswordInput(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $result = $provider->addData([
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => 'aim:enc:v2:whatever'],
            'processedTca' => ['columns' => ['api_key' => ['config' => ['type' => 'input']]]],
        ]);

        self::assertSame('', $result['databaseRow']['api_key']);
        self::assertSame('password', $result['processedTca']['columns']['api_key']['config']['type']);
        self::assertStringContainsString('placeholder.configured', $result['processedTca']['columns']['api_key']['config']['placeholder']);
    }

    #[Test]
    public function leavesANonSecretEndpointUrlFullyVisible(): void
    {
        // ApiKeyEncryption never encrypts an http(s):// value (see its own
        // isEncrypted()/encrypt() logic): a local Ollama/LM Studio endpoint
        // is not a secret, so it must stay visible, unmasked, untouched.
        $provider = new HideApiKey(new ApiKeyEncryption());
        $input = [
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => 'http://localhost:11434'],
            'processedTca' => ['columns' => ['api_key' => ['config' => ['type' => 'input']]]],
        ];

        $result = $provider->addData($input);

        self::assertSame($input, $result);
    }

    #[Test]
    public function leavesAnEmptyFieldUntouched(): void
    {
        $provider = new HideApiKey(new ApiKeyEncryption());
        $input = [
            'tableName' => 'tx_aim_configuration',
            'databaseRow' => ['api_key' => ''],
            'processedTca' => ['columns' => ['api_key' => ['config' => ['type' => 'input']]]],
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
