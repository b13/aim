<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Provider;

use B13\Aim\Provider\EndpointCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EndpointCredentialTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function urls(): array
    {
        return [
            'user and password' => ['https://svc:s3cret@gw.example.com/v1', 's3cret', 'https://svc@gw.example.com/v1'],
            'with a port' => ['http://svc:s3cret@gw:8443/v1', 's3cret', 'http://svc@gw:8443/v1'],
            'with a query' => ['https://svc:s3cret@gw/v1?region=eu', 's3cret', 'https://svc@gw/v1?region=eu'],
            'percent encoded password' => ['https://svc:a%2Fb@gw/v1', 'a/b', 'https://svc@gw/v1'],
            'with a fragment' => ['https://svc:s3cret@gw/v1#tail', 's3cret', 'https://svc@gw/v1#tail'],
            'no password' => ['https://svc@gw/v1', null, ''],
            'no userinfo at all' => ['https://gw.example.com/v1', null, ''],
        ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function splitTakesOutOnlyThePasswordHalf(string $url, ?string $secret, string $rest): void
    {
        $result = EndpointCredential::split($url);

        if ($secret === null) {
            self::assertNull($result);
            return;
        }
        self::assertSame($secret, $result['secret']);
        self::assertSame($rest, $result['url']);
    }

    #[Test]
    public function aSplitUrlIsRecognisedAsWaitingForItsCredential(): void
    {
        self::assertTrue(EndpointCredential::expectsCredential('https://svc@gw/v1'));
        self::assertFalse(EndpointCredential::expectsCredential('https://gw/v1'));
        self::assertFalse(EndpointCredential::expectsCredential('https://svc:already@gw/v1'));
    }

    #[Test]
    public function mergePutsTheCredentialBackWhereItCameFrom(): void
    {
        $original = 'https://svc:s3cret@gw.example.com:8443/v1?region=eu';
        $split = EndpointCredential::split($original);

        self::assertSame($original, EndpointCredential::merge($split['url'], $split['secret']));
    }

    #[Test]
    public function aPasswordNeedingEncodingSurvivesTheRoundTrip(): void
    {
        $split = EndpointCredential::split('https://svc:a%2Fb@gw/v1');
        $merged = EndpointCredential::merge($split['url'], $split['secret']);

        self::assertSame('https://svc:a%2Fb@gw/v1', $merged);
        self::assertSame('a/b', EndpointCredential::split($merged)['secret']);
    }

    #[Test]
    public function aUrlThatWantsNoCredentialIsLeftAlone(): void
    {
        self::assertSame('https://gw/v1', EndpointCredential::merge('https://gw/v1', 'sk-bearer-token'));
        self::assertSame('https://svc@gw/v1', EndpointCredential::merge('https://svc@gw/v1', ''));
    }

    /**
     * A row the split migration has not reached still carries its credential
     * inline, and the overview and the endpoint input both render that value,
     * so the password has to come out before it reaches either.
     */
    #[Test]
    public function forDisplayTakesThePasswordOut(): void
    {
        self::assertSame(
            'https://svc@gateway.example.com/v1',
            EndpointCredential::forDisplay('https://svc:s3cr3t-token@gateway.example.com/v1'),
        );
    }

    #[Test]
    public function forDisplayLeavesAnEndpointWithoutACredentialAlone(): void
    {
        self::assertSame('http://localhost:11434', EndpointCredential::forDisplay('http://localhost:11434'));
        self::assertSame('', EndpointCredential::forDisplay(''));
        self::assertSame(
            'https://svc@gateway.example.com/v1',
            EndpointCredential::forDisplay('https://svc@gateway.example.com/v1'),
        );
    }

    /**
     * Everything the URL carried has to come back, including the parts an
     * endpoint rarely has. Rebuilding used to drop the fragment, which meant
     * forDisplay() showed a shortened URL and saving stored the shortened one.
     */
    #[Test]
    public function aFragmentSurvivesTheRoundTrip(): void
    {
        $original = 'https://svc:s3cret@gw.example.com:8443/v1?region=eu#tail';
        $split = EndpointCredential::split($original);

        self::assertSame('https://svc@gw.example.com:8443/v1?region=eu#tail', $split['url']);
        self::assertSame($original, EndpointCredential::merge($split['url'], $split['secret']));
        self::assertSame('https://svc@gw/v1#tail', EndpointCredential::forDisplay('https://svc:s3cret@gw/v1#tail'));
    }

    /**
     * A password with no user name in front of it: the userinfo has to go
     * entirely, or what is left is a URL with a stray "@" in it.
     */
    #[Test]
    public function aPasswordWithoutAUserNameLeavesNoUserinfoBehind(): void
    {
        self::assertSame('https://gw/v1', EndpointCredential::split('https://:s3cret@gw/v1')['url']);
    }
}
