<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Backend\Form\Wizard;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Controller\Wizard\SuggestWizardController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression coverage for a real access-control bug: a fragment stored at
 * pid=0 is meant to be a "global" library entry, always assignable
 * regardless of who's editing (see PromptFragmentSuggestReceiver's own
 * constructor, which explicitly OR's `pid=0` into its query) - but before
 * checkRecordAccess() was overridden, the *parent* class's own per-row
 * check called BackendUtility::readPageAccess($row['pid'], ...), which
 * returns false for pid=0 on any non-admin user, silently dropping every
 * global fragment from a restricted editor's own search results despite
 * the query saying otherwise. This drives the real HTTP-level
 * SuggestWizardController::searchAction(), not the receiver directly, so a
 * regression in either class - or in how they're wired together via TCA's
 * receiverClass - would fail this test.
 */
final class PromptFragmentSuggestReceiverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Mounted', 'perms_everybody' => 1]);

        // Grants tables_select for tx_aim_prompt_fragment: the "restricted"
        // in restricted-editor is about page mounts, not fragment read
        // access - without this group, hasFragmentReadAccess() would zero
        // out this user's results regardless of the pid=0/site-scoping
        // logic these tests exist to cover. See
        // aUserWithoutFragmentReadAccessSeesNoResultsAtAll() below for the
        // dedicated no-tables_select case.
        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 5,
            'title' => 'Fragment readers',
            'tables_select' => 'tx_aim_prompt_fragment',
        ]);

        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $beUsers->insert('be_users', ['uid' => 50, 'username' => 'restricted-editor', 'admin' => 0, 'db_mountpoints' => '10', 'usergroup' => '5']);
        $beUsers->insert('be_users', ['uid' => 51, 'username' => 'no-fragment-access-editor', 'admin' => 0, 'db_mountpoints' => '10']);

        $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')->insert('tx_aim_prompt_fragment', [
            'pid' => 0,
            'title' => 'Global fragment',
            'prompt' => 'Shared tone.',
            'scope' => 'all',
        ]);
    }

    #[Test]
    public function aRestrictedUserSeesAGlobalFragmentInSuggestSearchResults(): void
    {
        $results = $this->search(50, 10);

        self::assertNotEmpty($results, 'The global (pid=0) fragment must show up for a restricted user too.');
        self::assertSame('Global fragment', $results[0]['label']);
    }

    #[Test]
    public function anAdminSeesTheSameGlobalFragment(): void
    {
        $results = $this->search(1, 10);

        self::assertNotEmpty($results);
        self::assertSame('Global fragment', $results[0]['label']);
    }

    /**
     * Regression test: the two tests above never actually type a search
     * term ('value' => ''), which skips prepareSelectStatement()'s whole
     * buildConstraintBlock() branch (SuggestWizardDefaultReceiver only
     * builds LIKE constraints when the search string is non-empty) - so
     * they never exercise the parent class's own
     * createPositionalParameter() calls. Our constructor's pid=0/pid IN
     * (...) OR condition uses createNamedParameter() on the very same
     * queryBuilder instance, and Doctrine DBAL cannot mix positional and
     * named parameters in one query: as soon as a real search term reaches
     * buildConstraintBlock(), executing the query throws
     * Doctrine\DBAL\ArrayParameters\Exception\MissingPositionalParameter
     * instead of returning results.
     */
    #[Test]
    public function typingASearchTermDoesNotThrow(): void
    {
        $results = $this->search(50, 10, 'Global');

        self::assertNotEmpty($results, 'The global fragment must still be found once a search term is typed.');
        self::assertSame('Global fragment', $results[0]['label']);
    }

    /**
     * Regression coverage for the "site tree" arm of the OR condition
     * (the earlier live/mutation-tested pid=0 tests above only exercise
     * the "global" arm - page 10 there has no site config at all, so
     * resolveAllowedFragmentPageIds() always degraded to []). Also
     * directly exercises the rewritten resolveAllowedFragmentPageIds()
     * (which now resolves site membership per distinct fragment-storage
     * pid instead of walking the whole page tree): a fragment stored in a
     * sysfolder within the SAME site as the page being edited must still
     * be suggested.
     */
    #[Test]
    public function aFragmentStoredInASysfolderWithinTheSameSiteIsSuggested(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 100, 'pid' => 0, 'title' => 'Site root', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 101, 'pid' => 100, 'title' => 'Sysfolder', 'doktype' => 254]);
        $pages->insert('pages', ['uid' => 102, 'pid' => 100, 'title' => 'Editing target']);
        $this->get(SiteWriter::class)->write('main-suggest-test', ['rootPageId' => 100, 'base' => '/']);

        $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment')->insert('tx_aim_prompt_fragment', [
            'pid' => 101,
            'title' => 'Sysfolder fragment',
            'prompt' => 'Site-local tone.',
            'scope' => 'all',
        ]);

        $results = $this->search(1, 102);
        $labels = array_column($results, 'label');

        self::assertContains('Global fragment', $labels);
        self::assertContains('Sysfolder fragment', $labels, 'A fragment stored within the same site tree must be suggested too.');
    }

    /**
     * Regression coverage for a real access-control bypass: this field's
     * only enforced restriction used to be pid-based site-scoping, with no
     * check at all for tables_select on tx_aim_prompt_fragment. A user
     * granted PAGE_EDIT but deliberately NOT tables_select on the fragment
     * table (a real, intentional TYPO3 permission combination) could still
     * browse/select every fragment title - including pid=0 global ones -
     * through this suggest field, bypassing the same tables_select check
     * PromptManagementController enforces on its own listings.
     */
    #[Test]
    public function aUserWithoutFragmentReadAccessSeesNoResultsAtAll(): void
    {
        $results = $this->search(51, 10);

        self::assertSame([], $results, 'A user without tables_select on tx_aim_prompt_fragment must not see any fragment, not even global (pid=0) ones.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(int $backendUserId, int $pid, string $value = ''): array
    {
        $backendUser = $this->setUpBackendUser($backendUserId);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $parsedBody = [
            'value' => $value,
            'tableName' => 'tx_aim_page_prompt_fragment',
            'fieldName' => 'fragment',
            'uid' => 'NEW1234567890',
            'pid' => (string)$pid,
            'dataStructureIdentifier' => '',
            'recordTypeValue' => '1',
        ];

        $url = 'https://typo3-testing.local/typo3/ajax/wizard/suggest/search';
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3/ajax/wizard/suggest/search',
            'REQUEST_METHOD' => 'POST',
        ];
        $request = new ServerRequest($url, 'POST', 'php://input', [], $serverParams);
        $request = $request->withParsedBody($parsedBody);
        $request = $request->withAttribute('route', new Route('/ajax/wizard/suggest/search', ['packageName' => 'typo3/cms-backend']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $response = $this->get(SuggestWizardController::class)->searchAction($request);
        self::assertInstanceOf(ResponseInterface::class, $response);

        return json_decode((string)$response->getBody(), true);
    }
}
