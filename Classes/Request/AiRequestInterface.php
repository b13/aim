<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Request;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Governance\PrivacyLevel;

/**
 * Contract for all AI request objects passed through the middleware pipeline.
 *
 * Each request type (VisionRequest, TextGenerationRequest, etc.) carries the
 * provider configuration and request-specific parameters. The pipeline may
 * swap the configuration during fallback via withConfiguration().
 */
interface AiRequestInterface
{
    public function getConfiguration(): ProviderConfiguration;

    /**
     * Return a copy of this request with a different provider configuration.
     * Used during fallback to swap the API key/model without reflection.
     */
    public function withConfiguration(ProviderConfiguration $configuration): static;

    /**
     * Return a copy of this request with additional metadata merged in.
     *
     * Example:
     *     $request = $request->withMetadata(['my_extension.context' => $value]);
     *     return $next->handle($request, $provider, $configuration);
     */
    public function withMetadata(array $additional): static;

    /**
     * Return a copy of this request with a per-request privacy level override.
     *
     * Folds into the existing strictness ladder (provider config → user
     * TSconfig → per-request), strictest wins. The override can only
     * escalate — it cannot relax a stricter level set upstream.
     *
     * Example (suppress logging for a health-check request):
     *     $request = $request->withPrivacyLevel(PrivacyLevel::None);
     */
    public function withPrivacyLevel(PrivacyLevel $level): static;

    /**
     * The privacy-level override carried on the request, or null when none
     * has been set. Returned for the logging middleware's strictness ladder.
     */
    public function getPrivacyLevelOverride(): ?PrivacyLevel;
}
