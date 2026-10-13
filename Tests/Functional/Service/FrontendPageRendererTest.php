<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Service;

use B13\Aim\Service\FrontendPageRenderer;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Http\Application;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FrontendPageRendererTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
        '../Tests/Functional/Fixtures/Extensions/test_internal_request_context',
    ];

    /**
     * The testing framework puts the page cache on the NullBackend, which
     * would let this test pass whatever the renderer does.
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'pages' => [
                        'backend' => Typo3DatabaseBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Home',
            'slug' => '/',
            'doktype' => 1,
            'is_siteroot' => 1,
        ]);
        $this->setUpFrontendRootPage(1, ['EXT:aim/Tests/Functional/Fixtures/Frontend/Page.typoscript']);

        $siteDirectory = Environment::getConfigPath() . '/sites/test';
        GeneralUtility::mkdir_deep($siteDirectory);
        file_put_contents($siteDirectory . '/config.yaml', implode("\n", [
            'rootPageId: 1',
            'base: \'https://example.com/\'',
            'languages:',
            '  - languageId: 0',
            '    title: English',
            '    locale: en_US.UTF-8',
            '    base: /',
            '',
        ]));
    }

    #[Test]
    public function rendersThePageWithoutStoringItInThePageCache(): void
    {
        $renderer = new FrontendPageRenderer(
            $this->get(Application::class),
            $this->get(SiteFinder::class),
            new NullLogger(),
        );

        $html = $renderer->renderPageHtml(1);

        self::assertNotNull($html);
        self::assertStringContainsString('Rendered by the frontend', $html);
        $pageCacheBackend = $this->get(CacheManager::class)->getCache('pages')->getBackend();
        self::assertInstanceOf(TaggableBackendInterface::class, $pageCacheBackend);
        self::assertSame([], $pageCacheBackend->findIdentifiersByTag('pageId_1'));
    }
}
