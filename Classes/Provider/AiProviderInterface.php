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
 * Marker interface that every AI provider must implement.
 *
 * Provider metadata (identifier, name, description, supported models) is
 * declared via the #[AsAiProvider] attribute and available on AiProviderManifest.
 *
 * Actual functionality is declared via capability interfaces
 * (e.g. VisionCapableInterface, ConversationCapableInterface).
 */
interface AiProviderInterface
{
}
