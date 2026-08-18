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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Which AI capability a prompt fragment (tx_aim_prompt_fragment.scope, or a
 * code-registered fragment from PromptFragmentRegistry) applies to.
 *
 * Mirrors the 6 capability interfaces in Classes/Capability/ rather than
 * inventing a new taxonomy. All matches every capability.
 */
enum PromptFragmentScope: string
{
    case All = 'all';
    case Text = 'text';
    case Vision = 'vision';
    case Translation = 'translation';
    case Conversation = 'conversation';
    case ToolCalling = 'toolCalling';
    case ImageGeneration = 'imageGeneration';

    /**
     * Parses a fragment's stored scope into a validated set of values: a
     * fragment can be tagged with more than one capability at once (e.g.
     * "text,vision"), stored the same comma-separated way TYPO3 core stores
     * any `selectCheckBox` field with no relation (see
     * tx_aim_prompt_fragment's `scope` column). Also accepts a plain PHP
     * array, so code-registered fragments can write the more natural
     * `'scope' => ['text', 'vision']`; TSconfig values and DB columns
     * are always plain strings, so they only ever use the comma-separated form.
     *
     * Invalid individual tokens are dropped (and logged, if a logger is
     * given) rather than invalidating the whole set. An empty or
     * entirely-invalid input falls back to `['all']`, matching this same
     * fallback's pre-existing single-value behavior.
     *
     * @return list<string>
     */
    public static function parseList(string|array $raw, ?LoggerInterface $logger = null): array
    {
        $tokens = is_array($raw) ? $raw : GeneralUtility::trimExplode(',', $raw, true);
        $valid = [];
        foreach ($tokens as $token) {
            $token = is_string($token) ? trim($token) : '';
            if ($token === '') {
                continue;
            }
            if (self::tryFrom($token) === null) {
                $logger?->warning(sprintf('Unknown prompt fragment scope "%s", ignoring it.', $token));
                continue;
            }
            $valid[$token] = true;
        }
        return $valid === [] ? [self::All->value] : array_keys($valid);
    }

    /**
     * Whether a fragment tagged with $storedScopes applies to a request for
     * this capability. Symmetric in both directions:
     *  - a fragment tagged with the literal "all" sentinel matches every
     *    requested capability (long-standing behavior, still how a real AI
     *    request, which only ever asks for one of the 6 concrete
     *    capabilities, never for All, resolves an "all"-tagged fragment);
     *  - a request for self::All matches every fragment regardless of
     *    what it's tagged with. Only the "preview as capability" picker and
     *    the list's own scope filter ever request All; a real AI request
     *    never does. So this only changes preview/filter behavior: "preview
     *    as All capabilities" means "show me anything," not "search for the now
     *    comparatively rare literal 'all' sentinel," which is what a naive
     *    `in_array(self::All->value, $storedScopes, true)` only check would
     *    have meant since new fragments default to all 6 concrete
     *    capabilities individually checked rather than to that sentinel.
     *
     * @param list<string> $storedScopes
     */
    public function matches(array $storedScopes): bool
    {
        return $this === self::All
            || in_array(self::All->value, $storedScopes, true)
            || in_array($this->value, $storedScopes, true);
    }

    /**
     * Filters a list of {prompt, scope} fragments down
     * to just the prompt texts matching this scope.
     *
     * @param list<array{prompt: string, scope: list<string>}> $fragments
     * @return list<string>
     */
    public function filterPrompts(array $fragments): array
    {
        $prompts = [];
        foreach ($fragments as $fragment) {
            if ($this->matches($fragment['scope'])) {
                $prompts[] = $fragment['prompt'];
            }
        }
        return $prompts;
    }
}
