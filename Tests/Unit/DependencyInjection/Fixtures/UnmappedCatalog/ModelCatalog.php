<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\DependencyInjection\Fixtures\UnmappedCatalog;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;

/**
 * A static catalog of audio models only, none of which maps to an AiM
 * capability.
 */
final class ModelCatalog
{
    /**
     * @return array<string, array{class: class-string, capabilities: list<Capability>}>
     */
    public function getModels(): array
    {
        return [
            'speech-to-text-model' => [
                'class' => Model::class,
                'capabilities' => [
                    Capability::INPUT_AUDIO,
                    Capability::SPEECH_TO_TEXT,
                ],
            ],
            'text-to-speech-model' => [
                'class' => Model::class,
                'capabilities' => [
                    Capability::INPUT_TEXT,
                    Capability::TEXT_TO_SPEECH,
                ],
            ],
        ];
    }
}
