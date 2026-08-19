<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Middleware;

use B13\Aim\Attribute\AsAiMiddleware;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\StreamChunkIterator;
use B13\Aim\Response\TextResponse;
use B13\Aim\Response\ToolCallingResponse;
use B13\AiLabel\Service\AiLabelApi;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Optional integration with the separate "ai_label" extension (b13/ai-label),
 * which lets editors/visitors see which backend records were AI-generated or
 * AI-modified (EU AI Act Article 50 transparency).
 *
 * A no-op unless ai_label is installed AND the request's own metadata names
 * which record this response is about to end up in. AiM has no way to know
 * that on its own, since what happens to $response->content after it's
 * returned is entirely up to the calling extension. Opt in per call via:
 *
 *   $ai->text()->prompt(...)->withMetadata([
 *       'aiLabel' => ['table' => 'sys_file_metadata', 'uid' => $uid, 'origin' => 'created'],
 *   ])->send();
 *
 * 'origin' is 'created' (brand-new AI content, the default if omitted) or
 * 'modified' (existing human content AI-edited). Mirrors ai_label's own
 * AiOrigin enum. ai_label's write goes through a real DataHandler run and
 * requires a backend user to attribute the change to (its own
 * resolveUser()). Calls made outside a backend context (frontend,
 * CLI/scheduler) will fail that check, which this middleware treats as a
 * non-fatal, logged no-op rather than surfacing it as an AiM error.
 *
 * Priority -850: after CostTrackingMiddleware (-800) so the request is
 * fully settled, before EventDispatchMiddleware (-900) /
 * CoreDispatchMiddleware (-1000) so this only ever runs for genuinely
 * successful responses.
 */
#[AsAiMiddleware(priority: -850)]
final class AiLabelMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        AiRequestInterface $request,
        AiProviderInterface $provider,
        ProviderConfiguration $configuration,
        AiMiddlewareHandler $next,
    ): TextResponse {
        $target = $this->resolveTarget($request);
        if ($target === null || !class_exists(AiLabelApi::class)) {
            return $next->handle($request, $provider, $configuration);
        }

        $response = $next->handle($request, $provider, $configuration);

        if (($response instanceof ConversationResponse || $response instanceof ToolCallingResponse) && $response->isStreaming()) {
            $this->deferLabel($response, $target);
            return $response;
        }

        if ($response->isSuccessful()) {
            $this->applyLabel($target);
        }

        return $response;
    }

    /**
     * @return array{table: string, uid: int, origin: string}|null
     */
    private function resolveTarget(AiRequestInterface $request): ?array
    {
        if (!property_exists($request, 'metadata') || !is_array($request->metadata)) {
            return null;
        }

        $raw = $request->metadata['aiLabel'] ?? null;
        if (!is_array($raw) || !isset($raw['table'], $raw['uid'])) {
            return null;
        }

        $table = (string)$raw['table'];
        $uid = (int)$raw['uid'];
        if ($table === '' || $uid <= 0) {
            return null;
        }

        $origin = (string)($raw['origin'] ?? 'created');
        if (!in_array($origin, ['created', 'modified'], true)) {
            $origin = 'created';
        }

        return ['table' => $table, 'uid' => $uid, 'origin' => $origin];
    }

    /**
     * @param array{table: string, uid: int, origin: string} $target
     */
    private function deferLabel(ConversationResponse|ToolCallingResponse $response, array $target): void
    {
        $streamIterator = $response->streamIterator;
        if (!$streamIterator instanceof StreamChunkIterator) {
            return;
        }

        register_shutdown_function(
            function () use ($streamIterator, $target): void {
                $this->applyLabelForDrainedStream($streamIterator, $target);
            },
        );
    }

    /**
     * What the shutdown function registered by deferLabel() actually calls.
     * Split out so a test can invoke it directly against a manually-drained
     * iterator, the same way CostTrackingMiddlewareTest exercises
     * applyStreamedCost(). PHP only runs registered shutdown functions at
     * the end of the whole test process, not per test.
     *
     * @param array{table: string, uid: int, origin: string} $target
     */
    private function applyLabelForDrainedStream(StreamChunkIterator $streamIterator, array $target): void
    {
        if ($streamIterator->getAccumulatedContent() === '') {
            return;
        }
        $this->applyLabel($target);
    }

    /**
     * @param array{table: string, uid: int, origin: string} $target
     */
    private function applyLabel(array $target): void
    {
        try {
            $api = GeneralUtility::makeInstance(AiLabelApi::class);
            if ($target['origin'] === 'modified') {
                $api->aiModified($target['table'], $target['uid']);
            } else {
                $api->aiCreated($target['table'], $target['uid']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ai_label integration failed for {table}:{uid}: {message}', [
                'table' => $target['table'],
                'uid' => $target['uid'],
                'message' => $e->getMessage(),
            ]);
        }
    }
}
