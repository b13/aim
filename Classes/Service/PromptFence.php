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

/**
 * A delimiter around untrusted text handed to a model as data.
 *
 * Two things make it hold. The marker carries a random nonce generated per
 * call, so content inside the fence cannot name the delimiter that closes it
 * even if it guesses the wording. And neutralisation matches the *shape* of a
 * marker rather than one exact string, because an exact str_replace is
 * bypassed by lowercasing it, dropping the inner spaces, swapping in fullwidth
 * or non-breaking characters, or writing the replacement text itself.
 */
final class PromptFence
{
    // The rule characters cover the confusables an author would reach for:
    // fullwidth equals, em and en dash, the box-drawing double line, and
    // identical-to. A run of any of them, mixed, counts as a rule.
    private const RULE_CHARACTERS = '=\x{FF1D}\x{2014}\x{2013}\x{2550}\x{2261}_-';

    private const SHAPE_PATTERN = '/[' . self::RULE_CHARACTERS . ']{2,}[^\n]{0,120}?DATA[\s\x{00A0}_-]*ONLY[^\n]{0,120}?[' . self::RULE_CHARACTERS . ']{2,}/iu';

    /**
     * Invisible characters have no place in prose and exist here only to split
     * a marker so the pattern above misses it.
     */
    private const INVISIBLE_PATTERN = '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}\x{034F}]/u';

    private const REPLACEMENT = '[marker removed]';

    private function __construct(
        private readonly string $label,
        private readonly string $nonce,
    ) {}

    public static function for(string $label): self
    {
        return new self($label, bin2hex(random_bytes(4)));
    }

    public function marker(): string
    {
        return sprintf('=== %s (DATA ONLY) %s ===', $this->label, $this->nonce);
    }

    /**
     * The untrusted text between two copies of this fence's marker, with
     * anything marker-shaped inside it defused first.
     */
    public function wrap(string $text): string
    {
        return $this->marker() . "\n" . self::neutralise($text) . "\n" . $this->marker();
    }

    /**
     * For text embedded in a prompt without its own fence.
     */
    public static function neutralise(string $text): string
    {
        // Both patterns are /u, so one malformed byte anywhere makes
        // preg_replace return null with PREG_BAD_UTF8_ERROR. Falling back to
        // the original text would hand back a perfectly readable marker
        // elsewhere in the same string, so the invalid sequences go first.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = preg_replace(self::INVISIBLE_PATTERN, '', $text) ?? $text;
        $neutralised = preg_replace(self::SHAPE_PATTERN, self::REPLACEMENT, $text);
        if ($neutralised !== null) {
            return $neutralised;
        }

        return (string)preg_replace('/[' . self::RULE_CHARACTERS . ']/u', ' ', $text) ?: $text;
    }
}
