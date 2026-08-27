<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Command;

use B13\Aim\Command\CalibrateVoice;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers only the parts reachable without a working AI provider fixture
 * (see SiteVoiceCalibratorTest's docblock for why that's the
 * established boundary in this codebase): command registration, the
 * "no site root pages configured at all" no-op, and the
 * no-content-found failure path via --page. The actual successful
 * calibrate-and-save path was verified manually against a real site
 * (camino-1) instead.
 */
final class CalibrateVoiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function commandIsRegisteredInTheConsoleRegistry(): void
    {
        $registry = $this->get(CommandRegistry::class);
        self::assertTrue($registry->has('aim:calibrateVoice'));
        self::assertInstanceOf(CalibrateVoice::class, $registry->get('aim:calibrateVoice'));
    }

    #[Test]
    public function reportsNoSiteRootPagesWhenNoneAreConfiguredAndNoPageOptionIsGiven(): void
    {
        $tester = $this->runCommandAndReturnTester([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No site root pages found', $tester->getDisplay());
    }

    #[Test]
    public function failsWithAClearMessageWhenTheGivenPageHasNoContentAtAll(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 5, 'pid' => 0, 'title' => 'Empty page']);

        $tester = $this->runCommandAndReturnTester(['--page' => '5', '--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No text content found', $tester->getDisplay());
    }

    /**
     * Regression test: the saved fragment's title used to be a hardcoded
     * English literal (self::FRAGMENT_TITLE); it's now resolved through
     * LanguageServiceFactory from an xlf key
     * (commands.calibrateVoice.fragmentTitle) so translators can localize
     * it, matching the site's own default language rather than the CLI
     * process's own locale. This only proves the key resolves to real
     * content, not a raw "LLL:..." passthrough or an empty string, since
     * the actual save path (resolveFragmentTitle()'s own call site) needs a
     * working AI provider fixture this test suite doesn't have, per this
     * class's own docblock.
     */
    #[Test]
    public function theFragmentTitleLabelResolvesToRealContentNotARawKey(): void
    {
        $title = $this->get(LanguageServiceFactory::class)->create('en')->sL(
            'LLL:EXT:aim/Resources/Private/Language/locallang_module.xlf:commands.calibrateVoice.fragmentTitle'
        );

        self::assertSame('Voice (auto-calibrated)', $title);
    }

    private function runCommandAndReturnTester(array $options): CommandTester
    {
        $tester = new CommandTester($this->get(CalibrateVoice::class));
        $tester->execute($options);

        return $tester;
    }
}
