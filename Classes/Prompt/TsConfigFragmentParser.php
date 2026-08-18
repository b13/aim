<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Prompt;

use Psr\Log\LoggerInterface;

/**
 * Parses the `aim.promptFragments.<key>.prompt`/`.scope` TSconfig namespace,
 * shared by both Page TSconfig (PagePromptResolver) and User/Group TSconfig
 * (UserPromptFragmentResolver).
 *
 * Example TSconfig:
 *   aim.promptFragments.watermark.prompt = Always add a small diagonal watermark.
 *   aim.promptFragments.watermark.scope = imageGeneration
 *   aim.promptFragments.brandVoice.prompt = Write in a warm, second-person voice.
 */
final class TsConfigFragmentParser
{
    /**
     * A fragment may name more than one capability, comma-separated (e.g.
     * `aim.promptFragments.watermark.scope = text,imageGeneration`).
     * Same convention as the DB fragment's `scope` column (see
     * PromptFragmentScope::parseList()). An unrecognized individual token
     * (typo, e.g. "imageGeneraton") is dropped rather than invalidating the
     * fragment's other, valid scopes; a value that's entirely empty or
     * entirely invalid falls back to "all". A fragment that unexpectedly
     * applies everywhere is far more noticeable (and thus fixable) than one
     * that silently never fires. $logger is optional so this stays a plain,
     * dependency-free, easily testable parse function when the caller
     * doesn't care about surfacing the warning.
     *
     * @param array<string, mixed> $promptFragmentsTsConfig e.g. $tsConfig['aim.']['promptFragments.'] ?? []
     * @return list<array{prompt: string, scope: list<string>}>
     */
    public static function parse(array $promptFragmentsTsConfig, ?LoggerInterface $logger = null): array
    {
        $fragments = [];
        foreach ($promptFragmentsTsConfig as $key => $value) {
            if (!is_string($key) || !str_ends_with($key, '.') || !is_array($value)) {
                continue;
            }
            $prompt = is_string($value['prompt'] ?? null) ? trim($value['prompt']) : '';
            if ($prompt === '') {
                continue;
            }
            $rawScope = is_string($value['scope'] ?? null) ? $value['scope'] : '';
            $fragments[] = [
                'prompt' => $prompt,
                'scope' => PromptFragmentScope::parseList($rawScope, $logger),
            ];
        }
        return $fragments;
    }
}
