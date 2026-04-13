<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Capability;

/**
 * Marker interface for AI capabilities.
 *
 * Each capability (vision, conversation, text generation, etc.) has its
 * own interface extending this one, declaring the method the provider must
 * implement. The system discovers capabilities via instanceof checks
 * against these interfaces - no registration needed.
 */
interface AiCapabilityInterface
{
}
