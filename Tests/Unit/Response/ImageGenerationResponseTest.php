<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Response;

use B13\Aim\Response\GeneratedImage;
use B13\Aim\Response\ImageGenerationResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageGenerationResponseTest extends TestCase
{
    #[Test]
    public function isSuccessfulRequiresAtLeastOneImageAndNoErrors(): void
    {
        $withImage = new ImageGenerationResponse([GeneratedImage::fromBase64('base64data', 'image/png')]);
        self::assertTrue($withImage->isSuccessful());

        $withoutImages = new ImageGenerationResponse();
        self::assertFalse($withoutImages->isSuccessful());

        $withError = new ImageGenerationResponse(
            [GeneratedImage::fromBase64('base64data', 'image/png')],
            errors: ['provider failed'],
        );
        self::assertFalse($withError->isSuccessful());
    }

    #[Test]
    public function generatedImageBuildsDataUri(): void
    {
        $image = GeneratedImage::fromBase64('aGVsbG8=', 'image/png');

        self::assertFalse($image->isUrl());
        self::assertSame('data:image/png;base64,aGVsbG8=', $image->toDataUri());
    }

    #[Test]
    public function generatedImageFromUrlIsFlaggedAsUrl(): void
    {
        $image = GeneratedImage::fromUrl('https://provider.example/tmp/abc123.png');

        self::assertTrue($image->isUrl());
        self::assertSame('https://provider.example/tmp/abc123.png', $image->url);
    }
}
