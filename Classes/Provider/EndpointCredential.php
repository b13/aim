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

use TYPO3\CMS\Core\Utility\HttpUtility;

/**
 * Splits a credential out of an endpoint URL for storage, and puts it back for
 * a request.
 *
 * Some hosts only do basic auth, where the credential belongs in the URL and a
 * bearer token is rejected. Storing such a URL whole would keep the credential
 * in a plaintext column and show it in the backend, so the password half is
 * stored encrypted in api_key and the URL keeps only its user half. The user
 * half is an identifier rather than a secret, and it is what marks a
 * configuration as wanting its credential in the URL rather than in a header.
 */
final class EndpointCredential
{
    /**
     * Splits scheme://user:secret@host/path into the secret and the same URL
     * without it. Returns null when there is no password to take out.
     *
     * @return array{secret: string, url: string}|null
     */
    public static function split(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['pass'] ?? '') === '') {
            return null;
        }

        return [
            'secret' => rawurldecode((string)$parts['pass']),
            'url' => self::rebuild($parts, null),
        ];
    }

    /**
     * The same URL with any password taken out, for showing to a human. A row
     * the split migration has not reached still carries its credential inline,
     * so anything that renders an endpoint has to go through this.
     */
    public static function forDisplay(string $url): string
    {
        return self::split($url)['url'] ?? $url;
    }

    /**
     * Whether this URL is waiting for a credential: it names a user but carries
     * no password, which is what split() leaves behind.
     */
    public static function expectsCredential(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && ($parts['user'] ?? '') !== ''
            && ($parts['pass'] ?? '') === '';
    }

    /**
     * Puts the credential back as the password half. Returns the URL unchanged
     * when it is not waiting for one.
     */
    public static function merge(string $url, string $secret): string
    {
        if ($secret === '' || !self::expectsCredential($url)) {
            return $url;
        }

        $parts = parse_url($url);

        return self::rebuild($parts === false ? [] : $parts, $secret);
    }

    /**
     * HttpUtility::buildUrl() is the inverse of parse_url() and passes every
     * part through unencoded, so the secret is encoded here and the userinfo is
     * removed as a whole: buildUrl() only checks isset() on 'user', and an empty
     * one would still emit the "@".
     *
     * @param array<string, mixed> $parts
     */
    private static function rebuild(array $parts, ?string $secret): string
    {
        unset($parts['pass']);
        $parts['scheme'] ??= 'https';
        if (($parts['user'] ?? '') === '') {
            unset($parts['user']);
        } elseif ($secret !== null && $secret !== '') {
            $parts['pass'] = rawurlencode($secret);
        }

        return HttpUtility::buildUrl($parts);
    }
}
