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

use B13\Aim\Backend\PageTreeResolver;

/**
 * Crawls a site (root page + a bounded slice of its subpages, see
 * PageTreeResolver::resolveBoundedSlice()) and calibrates a voice
 * instruction + examples from the combined real content, for
 * aim:calibrateVoice.
 *
 * "Summarize meaningfully instead of huge prompts" (the actual requirement
 * behind this class) is handled by SELECTION, not compression: pages are
 * accumulated in crawl order (root first, then breadth-first) only while
 * the running total stays within VoiceCalibrationService::MAX_LENGTH, and
 * a page is only ever included whole - never cut off mid-sentence - except
 * as a last-resort safety net when even the very first page alone already
 * exceeds the budget (so calibration still has something to analyze rather
 * than nothing). This keeps every included page coherent and avoids a
 * second AI call (a summarization pass) just to shrink the input.
 */
final class SiteVoiceCalibrator
{
    public function __construct(
        private readonly PageTreeResolver $pageTreeResolver,
        private readonly PageContentExtractor $contentExtractor,
        private readonly VoiceCalibrationService $voiceCalibrationService,
    ) {}

    /**
     * @return array{ok: true, tone: string, examples: string, pagesUsed: list<int>}|array{ok: false, message: string}
     */
    public function calibrateForRootPage(int $rootPageId, int $maxDepth, int $maxPages): array
    {
        $combined = $this->buildCombinedText($rootPageId, $maxDepth, $maxPages);
        if ($combined['text'] === '') {
            return ['ok' => false, 'message' => 'No text content found on any crawled page.'];
        }

        $result = $this->voiceCalibrationService->calibrate($combined['text']);
        if (!$result['ok']) {
            return $result;
        }

        return [
            'ok' => true,
            'tone' => $result['tone'],
            'examples' => $result['examples'],
            'pagesUsed' => $combined['pagesUsed'],
        ];
    }

    /**
     * The crawl-and-accumulate half of calibrateForRootPage(), split out since
     * it's DB-only and AI-call-free, making it independently testable without
     * needing a working AI provider the way the full calibration does.
     *
     * @return array{text: string, pagesUsed: list<int>}
     */
    public function buildCombinedText(int $rootPageId, int $maxDepth, int $maxPages): array
    {
        $candidatePageIds = $this->pageTreeResolver->resolveBoundedSlice($rootPageId, $maxDepth, $maxPages);

        $sections = [];
        $pagesUsed = [];
        $totalLength = 0;
        foreach ($candidatePageIds as $pageId) {
            $pageText = trim($this->contentExtractor->extractTextWithSources([$pageId])['text']);
            if ($pageText === '') {
                continue;
            }

            if ($sections !== [] && $totalLength + mb_strlen($pageText) > VoiceCalibrationService::MAX_LENGTH) {
                // Adding this page whole would exceed the budget, and at
                // least one earlier page is already included. Stop here
                // rather than truncate it, so what's kept always reads as
                // complete, coherent pages.
                break;
            }
            if (mb_strlen($pageText) > VoiceCalibrationService::MAX_LENGTH) {
                $pageText = mb_substr($pageText, 0, VoiceCalibrationService::MAX_LENGTH);
            }

            $sections[] = $pageText;
            $pagesUsed[] = $pageId;
            $totalLength += mb_strlen($pageText);
        }

        return ['text' => implode("\n\n", $sections), 'pagesUsed' => $pagesUsed];
    }
}
