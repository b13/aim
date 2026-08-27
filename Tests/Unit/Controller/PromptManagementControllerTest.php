<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Controller;

use B13\Aim\Backend\PageTreeResolver;
use B13\Aim\Backend\SortUrlBuilder;
use B13\Aim\Controller\PromptManagementController;
use B13\Aim\Domain\Repository\PagePromptFragmentRepository;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Prompt\PromptPreviewService;
use B13\Aim\Service\PageContentExtractor;
use B13\Aim\Service\VoiceCalibrationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;

/**
 * Covers previewAction's "always HTTP 200, ok:bool in the body" contract:
 * deliberately not a 404/400 for expected failures (bad scope, provider not
 * available), unlike ProviderController::verifyProviderAction's 404s, which a
 * naive frontend fetch-error handler collapses into an opaque generic error
 * instead of showing the real, actionable message.
 *
 * Cases that need a real `pages` row and a real permission/webmount
 * resolution (page-not-found, page-not-accessible, an actually-selected
 * page id) are covered by the functional test instead. BackendUtility::getRecord()
 * and PageTreeResolver::resolveAccessiblePageIds()'s non-admin path both
 * need a real DB connection this plain TestCase doesn't have. Page id 0 is the one
 * page-existence/-accessibility case that IS unit-testable: previewAction
 * never touches either for it (see the controller's own comment on why 0
 * is exempt).
 */
final class PromptManagementControllerTest extends TestCase
{
    /**
     * Regression test: this endpoint composes and returns the full,
     * untruncated text of every matching fragment - it must check
     * tables_select for tx_aim_prompt_fragment specifically (not, say,
     * isAdmin() or the wrong table) BEFORE composition ever runs. Unlike
     * the functional-test suite's own coverage of the denied case (which
     * proves the end-to-end response shape), this is the one place able to
     * assert the exact permission/table pair checked and that
     * PromptPreviewService::preview() is never even invoked when denied.
     */
    #[Test]
    public function respondsOkFalseWhenTablesSelectIsDeniedAndNeverInvokesTheService(): void
    {
        $previewService = $this->createMock(PromptPreviewService::class);
        $previewService->expects(self::never())->method('preview');

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->expects(self::once())->method('check')->with('tables_select', 'tx_aim_prompt_fragment')->willReturn(false);

        $response = $this->preview($previewService, $this->createStub(ProviderConfigurationRepository::class), ['pageId' => '', 'scope' => 'text'], $backendUser);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function returnsTheServiceResultWrappedInOkTrueWhenNoPageIsSelected(): void
    {
        $previewService = $this->createMock(PromptPreviewService::class);
        $previewService->expects(self::once())->method('preview')->with(null, PromptFragmentScope::Text, null)->willReturn([
            'layers' => [],
            'composed' => 'Composed text.',
            'totalCharacterCount' => 14,
            'approximateTokenCount' => 4,
        ]);

        $response = $this->preview($previewService, $this->createStub(ProviderConfigurationRepository::class), ['pageId' => '', 'scope' => 'text']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertTrue($body['ok']);
        self::assertSame('Composed text.', $body['composed']);
    }

    #[Test]
    public function treatsPageIdZeroAsARealSelectedPageNotAsUnsetAndSkipsBothTheExistenceAndAccessibilityLookup(): void
    {
        $previewService = $this->createMock(PromptPreviewService::class);
        $previewService->expects(self::once())->method('preview')->with(0, PromptFragmentScope::All, null)->willReturn([
            'layers' => [],
            'composed' => '',
            'totalCharacterCount' => 0,
            'approximateTokenCount' => 0,
        ]);

        $response = $this->preview($previewService, $this->createStub(ProviderConfigurationRepository::class), ['pageId' => '0', 'scope' => 'all']);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string)$response->getBody(), true)['ok']);
    }

    #[Test]
    public function returns200WithOkFalseForAMalformedNonNumericPageId(): void
    {
        // A plain (int) cast would silently turn "banana" into 0, a real,
        // meaningfully different selection (the tree root) that skips both
        // the existence and accessibility checks below. Garbage input must
        // be rejected as invalid, not quietly resolved to a valid page id.
        $previewService = $this->createMock(PromptPreviewService::class);
        $previewService->expects(self::never())->method('preview');

        $response = $this->preview($previewService, $this->createStub(ProviderConfigurationRepository::class), ['pageId' => 'banana', 'scope' => 'text']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function returns200WithOkFalseForAnUnknownScope(): void
    {
        $previewService = $this->createMock(PromptPreviewService::class);
        $previewService->expects(self::never())->method('preview');

        $response = $this->preview($previewService, $this->createStub(ProviderConfigurationRepository::class), ['pageId' => '', 'scope' => 'not-a-real-scope']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function returns200WithOkFalseWhenTheProviderConfigurationIsNotInTheEnabledSet(): void
    {
        // Checked against findAllRawSorted(true) (the enabled set the
        // dropdown itself offers), not findByUid() against every
        // configuration: a disabled/draft config's uid must not validate
        // just because it exists in the table.
        $previewService = $this->createMock(PromptPreviewService::class);
        $previewService->expects(self::never())->method('preview');

        $configurationRepository = $this->createStub(ProviderConfigurationRepository::class);
        $configurationRepository->method('findAllRawSorted')->with(true)->willReturn([['uid' => 1]]);

        $response = $this->preview($previewService, $configurationRepository, ['pageId' => '', 'scope' => 'text', 'providerConfigurationUid' => '999']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function passesTheProviderUidThroughWhenItIsInTheEnabledSet(): void
    {
        $previewService = $this->createMock(PromptPreviewService::class);
        $previewService->expects(self::once())->method('preview')->with(null, PromptFragmentScope::All, 1)->willReturn([
            'layers' => [],
            'composed' => '',
            'totalCharacterCount' => 0,
            'approximateTokenCount' => 0,
        ]);

        $configurationRepository = $this->createStub(ProviderConfigurationRepository::class);
        $configurationRepository->method('findAllRawSorted')->with(true)->willReturn([['uid' => 1]]);

        $response = $this->preview($previewService, $configurationRepository, ['pageId' => '', 'scope' => 'all', 'providerConfigurationUid' => '1']);

        self::assertTrue(json_decode((string)$response->getBody(), true)['ok']);
    }

    /**
     * @param array<string, string> $body
     */
    private function preview(
        PromptPreviewService $previewService,
        ProviderConfigurationRepository $configurationRepository,
        array $body,
        ?BackendUserAuthentication $backendUser = null,
    ): ResponseInterface {
        // ModuleTemplateFactory/SortUrlBuilder/UriBuilder are `final`(-ish)
        // classes PHPUnit either can't double or that previewAction never
        // touches (they're only used by overviewAction), so uninitialized
        // placeholders are sufficient, same approach ProviderControllerTest
        // uses for its own untouched final deps. PagePromptFragmentRepository
        // and PageTreeResolver are likewise untouched for every case
        // covered here (pageId empty or 0).
        $moduleTemplateFactory = (new \ReflectionClass(ModuleTemplateFactory::class))->newInstanceWithoutConstructor();
        $pagePromptFragmentRepository = $this->createStub(PagePromptFragmentRepository::class);
        // PageTreeResolver is `final` with no constructor deps of its own,
        // so it's simplest to just construct it directly rather than double
        // it: none of these test cases exercise its non-null-pageId branch
        // anyway (see the class docblock above).
        $pageTreeResolver = new PageTreeResolver();
        $sortUrlBuilder = (new \ReflectionClass(SortUrlBuilder::class))->newInstanceWithoutConstructor();
        $uriBuilder = (new \ReflectionClass(UriBuilder::class))->newInstanceWithoutConstructor();
        $iconFactory = (new \ReflectionClass(IconFactory::class))->newInstanceWithoutConstructor();
        // Never touched by previewAction() either: same placeholder approach.
        $voiceCalibrationService = (new \ReflectionClass(VoiceCalibrationService::class))->newInstanceWithoutConstructor();
        $pageContentExtractor = (new \ReflectionClass(PageContentExtractor::class))->newInstanceWithoutConstructor();

        $controller = new PromptManagementController(
            $moduleTemplateFactory,
            $previewService,
            $configurationRepository,
            $pagePromptFragmentRepository,
            $pageTreeResolver,
            $sortUrlBuilder,
            $uriBuilder,
            $iconFactory,
            $voiceCalibrationService,
            $pageContentExtractor,
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        // previewAction()'s first line checks tables_select for
        // tx_aim_prompt_fragment (see the functional-test suite's own
        // aUserWithoutTablesSelect... coverage for the denied case, and
        // respondsOkFalseWhenTablesSelectIsDenied below for the
        // permission-check itself) - a permissive default here keeps every
        // OTHER case in this class exercising the scope/pageId/provider
        // logic it actually targets.
        if ($backendUser === null) {
            $backendUser = $this->createStub(BackendUserAuthentication::class);
            $backendUser->method('check')->willReturn(true);
        }
        $GLOBALS['BE_USER'] = $backendUser;

        return $controller->previewAction($request);
    }
}
