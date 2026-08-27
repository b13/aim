<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Backend\Form;

use B13\Aim\Backend\Form\PagePromptFragmentLabelUserFunc;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression coverage for a real bug: a hidden fragment's title used to be
 * silently filtered out by the default HiddenRestriction (tx_aim_prompt_fragment's
 * own ctrl.enablecolumns.disable='hidden'), rendering the "(no fragment
 * selected)" fallback for an assignment that genuinely does have one
 * selected - this only reproduces via the scalar/raw-row fallback path
 * (resolveTitle()'s else branch), not the array shape FormEngine's own
 * TcaGroup data provider hands in during normal editing.
 */
final class PagePromptFragmentLabelUserFuncTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function resolvesTheTitleOfAHiddenFragmentInsteadOfReportingNoneSelected(): void
    {
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 0,
            'title' => 'Hidden fragment',
            'prompt' => 'Tone.',
            'scope' => 'all',
            'hidden' => 1,
        ]);
        $fragmentUid = (int)$fragments->lastInsertId();

        $params = ['row' => ['fragment' => (string)$fragmentUid]];
        $this->get(PagePromptFragmentLabelUserFunc::class)->getLabel($params);

        self::assertSame('Hidden fragment', $params['title']);
    }

    #[Test]
    public function stillReportsNoFragmentSelectedForAGenuinelyEmptyAssignment(): void
    {
        $params = ['row' => ['fragment' => '0']];
        $this->get(PagePromptFragmentLabelUserFunc::class)->getLabel($params);

        self::assertSame('(no fragment selected)', $params['title']);
    }

    #[Test]
    public function stillReportsNoFragmentSelectedWhenTheReferencedFragmentIsDeleted(): void
    {
        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', [
            'pid' => 0,
            'title' => 'Deleted fragment',
            'prompt' => 'Tone.',
            'scope' => 'all',
            'deleted' => 1,
        ]);
        $fragmentUid = (int)$fragments->lastInsertId();

        $params = ['row' => ['fragment' => (string)$fragmentUid]];
        $this->get(PagePromptFragmentLabelUserFunc::class)->getLabel($params);

        self::assertSame('(no fragment selected)', $params['title']);
    }
}
