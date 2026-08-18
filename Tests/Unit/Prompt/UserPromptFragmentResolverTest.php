<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Prompt;

use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Prompt\UserPromptFragmentResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class UserPromptFragmentResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function returnsEmptyListWhenNoBackendUserIsLoggedIn(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame([], (new UserPromptFragmentResolver(new NullLogger()))->getFragments(PromptFragmentScope::All));
    }

    #[Test]
    public function returnsFragmentsMatchingScopeFromUserTsConfig(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('getTSConfig')->willReturn([
            'aim.' => [
                'promptFragments.' => [
                    'watermark.' => ['prompt' => 'Add a watermark.', 'scope' => 'imageGeneration'],
                    'brandVoice.' => ['prompt' => 'Write in a warm voice.'],
                ],
            ],
        ]);
        $GLOBALS['BE_USER'] = $user;

        $resolver = new UserPromptFragmentResolver(new NullLogger());

        self::assertSame(
            ['Write in a warm voice.'],
            $resolver->getFragments(PromptFragmentScope::Text),
        );
        self::assertSame(
            ['Add a watermark.', 'Write in a warm voice.'],
            $resolver->getFragments(PromptFragmentScope::ImageGeneration),
        );
    }

    #[Test]
    public function matchesAFragmentTaggedWithMultipleCapabilities(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('getTSConfig')->willReturn([
            'aim.' => [
                'promptFragments.' => [
                    'accessibility.' => ['prompt' => 'Mention accessibility.', 'scope' => 'text,vision'],
                ],
            ],
        ]);
        $GLOBALS['BE_USER'] = $user;

        $resolver = new UserPromptFragmentResolver(new NullLogger());

        self::assertSame(['Mention accessibility.'], $resolver->getFragments(PromptFragmentScope::Text));
        self::assertSame(['Mention accessibility.'], $resolver->getFragments(PromptFragmentScope::Vision));
        self::assertSame([], $resolver->getFragments(PromptFragmentScope::Translation));
    }

    #[Test]
    public function returnsEmptyListWhenUserHasNoPromptFragmentsTsConfig(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('getTSConfig')->willReturn([]);
        $GLOBALS['BE_USER'] = $user;

        self::assertSame([], (new UserPromptFragmentResolver(new NullLogger()))->getFragments(PromptFragmentScope::All));
    }
}
