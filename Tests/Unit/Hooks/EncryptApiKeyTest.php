<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Hooks;

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Hooks\EncryptApiKey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class EncryptApiKeyTest extends TestCase
{
    private string $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $this->originalKey;
        parent::tearDown();
    }

    #[Test]
    public function encryptsPlaintextApiKeyOnSave(): void
    {
        $hook = new EncryptApiKey(new ApiKeyEncryption());
        $fieldArray = ['api_key' => 'sk-secret', 'title' => 'My Provider'];

        $hook->processDatamap_postProcessFieldArray('new', 'tx_aim_configuration', 'NEW1', $fieldArray, $this->createDataHandlerMock());

        self::assertTrue((new ApiKeyEncryption())->isEncrypted($fieldArray['api_key']));
        self::assertSame('My Provider', $fieldArray['title']);
    }

    #[Test]
    public function leavesAlreadyEncryptedValueUntouched(): void
    {
        $encryption = new ApiKeyEncryption();
        $hook = new EncryptApiKey($encryption);
        $alreadyEncrypted = $encryption->encrypt('sk-secret');
        $fieldArray = ['api_key' => $alreadyEncrypted];

        $hook->processDatamap_postProcessFieldArray('update', 'tx_aim_configuration', 1, $fieldArray, $this->createDataHandlerMock());

        self::assertSame($alreadyEncrypted, $fieldArray['api_key']);
    }

    #[Test]
    public function ignoresOtherTables(): void
    {
        $hook = new EncryptApiKey(new ApiKeyEncryption());
        $fieldArray = ['api_key' => 'sk-secret'];

        $hook->processDatamap_postProcessFieldArray('new', 'tt_content', 'NEW1', $fieldArray, $this->createDataHandlerMock());

        self::assertSame('sk-secret', $fieldArray['api_key']);
    }

    #[Test]
    public function ignoresUpdatesThatDoNotTouchApiKey(): void
    {
        $hook = new EncryptApiKey(new ApiKeyEncryption());
        $fieldArray = ['title' => 'Renamed'];

        $hook->processDatamap_postProcessFieldArray('update', 'tx_aim_configuration', 1, $fieldArray, $this->createDataHandlerMock());

        self::assertSame(['title' => 'Renamed'], $fieldArray);
    }

    #[Test]
    public function ignoresEmptyApiKey(): void
    {
        $hook = new EncryptApiKey(new ApiKeyEncryption());
        $fieldArray = ['api_key' => ''];

        $hook->processDatamap_postProcessFieldArray('update', 'tx_aim_configuration', 1, $fieldArray, $this->createDataHandlerMock());

        self::assertSame('', $fieldArray['api_key']);
    }

    private function createDataHandlerMock(): DataHandler
    {
        return $this->createMock(DataHandler::class);
    }
}
