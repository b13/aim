<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Provider;

/**
 * Strips provider credentials out of text before it is stored or returned.
 *
 * Matches the configured key in its literal, URL-encoded and JSON-escaped
 * forms, plus generic patterns for keys this code has never seen.
 */
final class CredentialRedactor
{
    public const REPLACEMENT = '***';

    /**
     * @var list<non-empty-string>
     */
    private const PATTERNS = [
        // Bearer/Basic/Token credentials out of a quoted Authorization header.
        '/\b(?:Bearer|Basic|Token)\s+[A-Za-z0-9._\-+\/=]{8,}/i',
        // Userinfo in a URL (https://user:token@host), the password half only,
        // so the host stays readable and the message keeps its diagnostic value.
        '/(:\/\/[^\/\s:@]+:)[^@\s\/]+@/',
        // Userinfo carrying no password (https://key@host, https://key:@host).
        // A user name on its own is documented as an identifier rather than a
        // secret, and it is taken out anyway: a whole class of providers uses
        // the key itself as the basic-auth user name, with an empty password,
        // and nothing in the string tells that apart from a real user name.
        // The host survives either way, which is what makes the message useful.
        '/(:\/\/)[^\/\s:@]+:?@/',
        // Vendor-prefixed keys (OpenAI sk-/sk-proj-, Anthropic sk-ant-,
        // Google AIza..., Hugging Face hf_...), whoever they belong to.
        '/\b(?:sk|rk|pk)-[A-Za-z0-9_\-]{8,}/',
        '/\bAIza[A-Za-z0-9_\-]{10,}/',
        '/\bhf_[A-Za-z0-9]{8,}/',
        // A key passed as a query parameter rather than a header.
        '/([?&](?:api[-_]?key|access[-_]?token|key)=)[^&\s"\']+/i',
    ];

    /**
     * @param string $knownSecret The configuration's own api_key, if any. Matched literally
     *                            plus in its URL-encoded and JSON-escaped forms.
     */
    public function redact(string $message, string $knownSecret = ''): string
    {
        if ($message === '') {
            return $message;
        }

        foreach ($this->knownSecretForms($knownSecret) as $needle) {
            $message = str_replace($needle, self::REPLACEMENT, $message);
        }

        foreach (self::PATTERNS as $pattern) {
            $message = (string)preg_replace_callback(
                $pattern,
                static fn(array $m): string => isset($m[1]) ? $m[1] . self::REPLACEMENT . (str_ends_with($m[0], '@') ? '@' : '') : self::REPLACEMENT,
                $message,
            );
        }

        return $message;
    }

    /**
     * The same secret can arrive in more than one encoding, and a plain
     * str_contains() check against the raw value, which is what this used to do,
     * misses every one of them.
     *
     * @return list<non-empty-string>
     */
    private function knownSecretForms(string $secret): array
    {
        if (strlen($secret) < 8) {
            // Too short to redact without mangling unrelated text. A value that
            // short is not a usable provider credential anyway.
            return [];
        }

        // When the api_key column holds a whole endpoint URL, only the password
        // half is the secret. Redacting the entire URL would work, but it takes
        // the host with it and leaves a message nobody can act on.
        if (preg_match('/^https?:\/\/[^\/\s:@]+:([^@\s\/]+)@/', $secret, $matches) === 1) {
            $secret = $matches[1];
        }

        $forms = [$secret, rawurlencode($secret), urlencode($secret)];

        $jsonEscaped = json_encode($secret);
        if (is_string($jsonEscaped)) {
            $forms[] = trim($jsonEscaped, '"');
        }

        return array_values(array_unique(array_filter($forms, static fn(string $v): bool => strlen($v) >= 8)));
    }
}
