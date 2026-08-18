<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Http\Application;

/**
 * Renders a page's actual frontend output in-process (no real HTTP round trip).
 *
 * Deliberately returns null (never throws) for every failure mode a caller
 * should treat as "just fall back to something else". No site configured
 * for the page (a common state for a brand-new page), a redirect/error
 * response, or any exception during rendering.
 */
final class FrontendPageRenderer
{
    public function __construct(
        private readonly Application $frontendApplication,
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger,
    ) {}

    public function renderPageHtml(int $pageUid): ?string
    {
        try {
            $request = $this->buildServerRequest($pageUid);
        } catch (\Throwable $e) {
            // warning(), not notice(): TYPO3's default log configuration
            // only writes warning and above to var/log/typo3_*.log, so a
            // notice() here was effectively invisible; this is exactly the
            // "why did it fall back?" signal an admin needs to find.
            $this->logger->warning('AiM frontend render: could not build a request for page ' . $pageUid . ': ' . $e->getMessage());
            return null;
        }

        $response = $this->dispatchWithGlobalStateGuard($request, $pageUid);
        if ($response === null) {
            return null;
        }
        if ($response->getStatusCode() >= 300) {
            $this->logger->warning(sprintf(
                'AiM frontend render: page %d responded with HTTP %d, falling back to stored content.',
                $pageUid,
                $response->getStatusCode(),
            ));
            return null;
        }

        return (string)$response->getBody();
    }

    private function buildServerRequest(int $pageUid): ServerRequestInterface
    {
        $site = $this->siteFinder->getSiteByPageId($pageUid);
        $uri = $site->getRouter()->generateUri($pageUid);

        if ($uri->getHost() === '') {
            throw new \RuntimeException(
                'Site "' . $site->getIdentifier() . '" has no host configured, cannot build a renderable URL.',
                1755500001,
            );
        }

        $httpHost = $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '');
        $serverParams = [
            'HTTP_HOST' => $httpHost,
            'SERVER_NAME' => $uri->getHost(),
            'SERVER_PORT' => $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80),
            'REQUEST_URI' => $uri->getPath() . ($uri->getQuery() ? '?' . $uri->getQuery() : ''),
            'SCRIPT_NAME' => '/index.php',
            'HTTPS' => $uri->getScheme() === 'https' ? 'on' : 'off',
        ];

        $request = new ServerRequest($uri, 'GET', 'php://input', ['Host' => [$httpHost]], $serverParams);

        return $request
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
    }

    /**
     * @see IndexingService::executeSubRequest() (apache-solr-for-typo3/solr), which has the same
     * snapshot/restore shape, trimmed to only what this class itself observes getting mutated.
     */
    private function dispatchWithGlobalStateGuard(ServerRequestInterface $request, int $pageUid): ?\Psr\Http\Message\ResponseInterface
    {
        $previousWorkingDirectory = getcwd();
        $hadBackendUser = array_key_exists('BE_USER', $GLOBALS);
        $previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $hadLanguageService = array_key_exists('LANG', $GLOBALS);
        $previousLanguageService = $GLOBALS['LANG'] ?? null;
        $hadRequest = array_key_exists('TYPO3_REQUEST', $GLOBALS);
        $previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;

        $assetCollector = GeneralUtility::makeInstance(AssetCollector::class);
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $previousAssetCollectorState = $assetCollector->getState();
        $previousPageRendererState = $pageRenderer->getState();

        $context = GeneralUtility::makeInstance(Context::class);
        $previousBackendUserAspect = $context->getAspect('backend.user');
        $previousWorkspaceAspect = $context->getAspect('workspace');

        chdir(Environment::getPublicPath());
        try {
            return $this->frontendApplication->handle($request);
        } catch (\Throwable $e) {
            $this->logger->warning('AiM frontend render failed for page ' . $pageUid . ': ' . $e->getMessage());
            return null;
        } finally {
            chdir($previousWorkingDirectory);
            if ($hadBackendUser) {
                $GLOBALS['BE_USER'] = $previousBackendUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }
            if ($hadLanguageService) {
                $GLOBALS['LANG'] = $previousLanguageService;
            } else {
                unset($GLOBALS['LANG']);
            }
            if ($hadRequest) {
                $GLOBALS['TYPO3_REQUEST'] = $previousRequest;
            } else {
                unset($GLOBALS['TYPO3_REQUEST']);
            }
            $assetCollector->updateState($previousAssetCollectorState);
            $pageRenderer->updateState($previousPageRendererState);
            $context->setAspect('backend.user', $previousBackendUserAspect);
            $context->setAspect('workspace', $previousWorkspaceAspect);
        }
    }
}
