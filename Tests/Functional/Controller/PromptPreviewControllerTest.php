<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Controller;

use B13\Aim\Controller\PromptPreviewController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the parts of previewAction's page validation that need a real
 * `pages` row and a real backend user (existence, and — the actual security
 * fix this listing rework was built for — accessibility) — not
 * unit-testable without booting TYPO3 (see
 * Tests/Unit/Controller/PromptPreviewControllerTest.php for everything that
 * doesn't need the database, including the pageId=0 case).
 *
 * Page tree:
 *   1  "Siteroot"          -- used by the admin-user existence tests
 *   10 "Mounted"           -- perms_everybody grants PAGE_SHOW; the restricted user's webmount
 *   20 "Not mounted"       -- perms_everybody grants PAGE_SHOW too, but outside the restricted user's mount
 *   30 "Subtree root"      -- has a fragment; used for overviewAction()'s tree-selection filtering
 *     31 "Subtree child"   -- has a fragment, child of 30
 *   40 "Outside subtree"   -- has a fragment, unrelated top-level page
 *   60 "Empty subtree"     -- no fragments anywhere in it; used for the
 *                             "wrong empty-state message" regression below
 */
final class PromptPreviewControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Siteroot', 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Mounted', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 20, 'pid' => 0, 'title' => 'Not mounted', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 30, 'pid' => 0, 'title' => 'Subtree root']);
        $pages->insert('pages', ['uid' => 31, 'pid' => 30, 'title' => 'Subtree child']);
        $pages->insert('pages', ['uid' => 40, 'pid' => 0, 'title' => 'Outside subtree']);
        $pages->insert('pages', ['uid' => 60, 'pid' => 0, 'title' => 'Empty subtree']);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 30, 'parent_page' => 30, 'title' => 'F1', 'prompt' => 'Tone.', 'scope' => 'all']);
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 31, 'parent_page' => 31, 'title' => 'F2', 'prompt' => 'Tone.', 'scope' => 'all']);
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 40, 'parent_page' => 40, 'title' => 'F3', 'prompt' => 'Tone.', 'scope' => 'all']);

        $beUsers = $this->getConnectionPool()->getConnectionForTable('be_users');
        $beUsers->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $beUsers->insert('be_users', ['uid' => 50, 'username' => 'restricted-editor', 'admin' => 0, 'db_mountpoints' => '10']);
    }

    #[Test]
    public function respondsOkTrueWhenTheSelectedPageExistsAndTheUserIsAnAdmin(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->preview(['pageId' => '1', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string)$response->getBody(), true)['ok']);
    }

    #[Test]
    public function respondsOkFalseWithA200WhenTheSelectedPageDoesNotExist(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->preview(['pageId' => '404404', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function respondsOkTrueForARestrictedUserPreviewingAPageInsideTheirOwnWebmount(): void
    {
        $this->setUpBackendUser(50);

        $response = $this->preview(['pageId' => '10', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string)$response->getBody(), true)['ok']);
    }

    #[Test]
    public function respondsOkFalseForARestrictedUserPreviewingAnExistingPageOutsideTheirWebmount(): void
    {
        // This is the actual security fix this module's listing rework was
        // built for: page 20 exists and is publicly PAGE_SHOW-permitted
        // (perms_everybody), but the restricted user's webmount is only
        // page 10 — the request must be rejected regardless of the page's
        // own permission bits.
        $this->setUpBackendUser(50);

        $response = $this->preview(['pageId' => '20', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function overviewActionWithNoPageSelectedListsPagesFromTheWholeInstallation(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(0)->getBody();

        self::assertStringContainsString('Subtree root', $body);
        self::assertStringContainsString('Subtree child', $body);
        self::assertStringContainsString('Outside subtree', $body);
    }

    #[Test]
    public function overviewActionWithAPageSelectedNarrowsTheListToItsSubtree(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(30)->getBody();

        self::assertStringContainsString('Subtree root', $body);
        self::assertStringContainsString('Subtree child', $body);
        self::assertStringNotContainsString('Outside subtree', $body);
    }

    #[Test]
    public function selectingAPageAlwaysOffersAPreviewThisPageActionRegardlessOfWhetherItOwnsAFragment(): void
    {
        // Page 60 owns no fragment (so it's never a row in the list), but
        // it might still inherit one from an ancestor — the whole point of
        // this standalone trigger is to make that checkable even then.
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(60)->getBody();

        self::assertStringContainsString('pp-panel-selected', $body);
        self::assertStringContainsString('data-page-id="60"', $body);
    }

    #[Test]
    public function noPageSelectedShowsNoPreviewThisPageAction(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(0)->getBody();

        self::assertStringNotContainsString('pp-panel-selected', $body);
    }

    #[Test]
    public function selectingASubtreeWithNoFragmentsShowsTheSubtreeSpecificEmptyStateNotTheInstallationWideOne(): void
    {
        // Regression: a selected page/subtree with zero fragments must not
        // claim "no page in this installation has a fragment" — pages 30/31/40
        // in the same installation DO have fragments, just not under page 60.
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(60)->getBody();

        self::assertStringContainsString('nor any of its subpages has its own AI prompt fragment configured', $body);
        self::assertStringNotContainsString('No page in this installation has an AI prompt fragment configured yet', $body);
    }

    #[Test]
    public function theSubtreeSpecificEmptyStateOffersACreatePromptLinkWhenTheUserCanEditTheSelectedPage(): void
    {
        $this->setUpBackendUser(1);

        $body = (string)$this->overview(60)->getBody();

        self::assertStringContainsString('columnsOnly%5Bpages%5D%5B0%5D=tx_aim_prompt_fragments', $body);
    }

    /**
     * @param array<string, string> $body
     */
    private function preview(array $body): ResponseInterface
    {
        $controller = $this->get(PromptPreviewController::class);
        $request = (new ServerRequest())->withParsedBody($body);

        return $controller->previewAction($request);
    }

    private function overview(int $selectedPageId): ResponseInterface
    {
        // ModuleTemplate reads $GLOBALS['LANG'] directly — setUpBackendUser()
        // populates $GLOBALS['BE_USER'] but not this, so overviewAction()
        // (unlike previewAction(), which never touches ModuleTemplate) needs
        // it set up explicitly first.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $controller = $this->get(PromptPreviewController::class);
        $request = $this->buildRequestWithNormalizedParams($selectedPageId);

        return $controller->overviewAction($request);
    }

    private function buildRequestWithNormalizedParams(int $selectedPageId): ServerRequest
    {
        $url = 'https://typo3-testing.local/typo3/module/admin/aim/prompt-preview' . ($selectedPageId > 0 ? '?id=' . $selectedPageId : '');
        $serverParams = [
            'HTTP_HOST' => 'typo3-testing.local',
            'SERVER_NAME' => 'typo3-testing.local',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => parse_url($url, PHP_URL_PATH) . ($selectedPageId > 0 ? '?id=' . $selectedPageId : ''),
            'REQUEST_METHOD' => 'GET',
        ];
        $request = new ServerRequest($url, 'GET', 'php://input', [], $serverParams);
        $request = $request->withQueryParams($selectedPageId > 0 ? ['id' => (string)$selectedPageId] : []);
        $request = $request->withAttribute('route', new Route('/module/admin/aim/prompt-preview', ['packageName' => 'b13/aim']));
        $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
