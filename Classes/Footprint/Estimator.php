<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later OR MPL-2.0
 *
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 * It is also available under the GNU General Public License, either
 * version 2 of the License, or any later version.
 */

namespace B13\Aim\Footprint;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Request\AiRequestInterface;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\ToolCallingRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\TextResponse;

/**
 * Estimates the usage-phase energy and CO2e of a text-generating request.
 *
 * Hosted providers: PHP port of the EcoLogits LLM inference model,
 * ecologits/impacts/llm.py by GenAI Impact, https://github.com/genai-impact/ecologits.
 * This port is available under MPL-2.0 as well as GPL-2.0-or-later.
 * Local providers: inference time × the power draw configured on the provider
 * configuration. See Documentation/EnvironmentalImpact.md for what is not counted.
 */
class Estimator
{
    /** AiM provider identifier => EcoLogits provider */
    private const PROVIDER_MAP = [
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'gemini' => 'google_genai',
        'mistral' => 'mistralai',
    ];

    /** Providers whose completion token count excludes reasoning tokens */
    private const REASONING_REPORTED_SEPARATELY = ['gemini'];

    private const SUPPORTED_REQUESTS = [
        TextGenerationRequest::class,
        VisionRequest::class,
        ConversationRequest::class,
        ToolCallingRequest::class,
        TranslationRequest::class,
    ];

    private const LOCAL_DEFAULT_ZONE = 'WOR';

    // EcoLogits constants, ecologits/impacts/llm.py
    private const GPU_ENERGY_ALPHA = 1.1665273170451914e-06;
    private const GPU_ENERGY_BETA = -0.011205921025579175;
    private const GPU_ENERGY_GAMMA = 4.052928146734005e-05;
    private const LATENCY_ALPHA = 0.0006785088094353663;
    private const LATENCY_BETA = 0.0003119310311688259;
    private const LATENCY_GAMMA = 0.019473717579473387;
    private const MODEL_QUANTIZATION_BITS = 16;
    private const GPU_MEMORY_GB = 80;
    private const SERVER_GPUS = 8;
    private const SERVER_POWER_KW = 1.2;
    private const BATCH_SIZE = 64;
    private const GPU_EMBODIED_GWP_KG = 273;
    private const SERVER_EMBODIED_GWP_KG = 5700;
    private const HARDWARE_LIFESPAN_SECONDS = 3 * 365 * 24 * 60 * 60;

    public function __construct(
        private readonly EcoLogitsData $data,
    ) {}

    public function estimate(
        AiRequestInterface $request,
        ?TextResponse $response,
        ProviderConfiguration $configuration,
        int $durationMs,
        bool $streamed = false,
    ): Estimate|Unavailable {
        if ($response === null || !$response->isSuccessful()) {
            return Unavailable::RequestFailed;
        }
        if (!$this->isSupported($request)) {
            return Unavailable::UnsupportedRequestType;
        }

        // A configured power draw says the model runs on known hardware,
        // e.g. an OpenAI-compatible endpoint served by a local Ollama.
        $watts = self::positiveFloat($configuration->get('power_draw_watts'));
        if ($watts !== null) {
            return $this->estimateFromDuration($watts, $response, $configuration, $durationMs, $streamed);
        }
        $ecoLogitsProvider = self::PROVIDER_MAP[$configuration->providerIdentifier] ?? null;
        if ($ecoLogitsProvider !== null) {
            $usage = $response->usage;
            $modelName = $usage->modelUsed !== '' ? $usage->modelUsed : $configuration->model;
            return $this->estimateFromModel($ecoLogitsProvider, self::outputTokens($usage, $configuration), $modelName, $configuration, $durationMs);
        }
        return Unavailable::NoDataSource;
    }

    /**
     * The CO2e smart routing avoided by answering with $answered instead of
     * $requested, the configuration it routed away from: the requested model's
     * estimate for the same output tokens minus the answer's, low end minus
     * high end and vice versa. Both leave out the measured duration, which
     * belongs to one of the two models only. Null unless both are model estimates.
     */
    public function estimateAvoided(
        AiRequestInterface $request,
        ?TextResponse $response,
        ProviderConfiguration $answered,
        ProviderConfiguration $requested,
    ): ?Avoided {
        $answeredProvider = self::PROVIDER_MAP[$answered->providerIdentifier] ?? null;
        $requestedProvider = self::PROVIDER_MAP[$requested->providerIdentifier] ?? null;
        if ($answeredProvider === null || $requestedProvider === null
            || self::positiveFloat($answered->get('power_draw_watts')) !== null
            || self::positiveFloat($requested->get('power_draw_watts')) !== null
            || $response === null || !$response->isSuccessful() || !$this->isSupported($request)
        ) {
            return null;
        }
        $usage = $response->usage;
        $outputTokens = self::outputTokens($usage, $answered);
        $answeredEstimate = $this->estimateFromModel(
            $answeredProvider,
            $outputTokens,
            $usage->modelUsed !== '' ? $usage->modelUsed : $answered->model,
            $answered,
            0,
        );
        $requestedEstimate = $this->estimateFromModel($requestedProvider, $outputTokens, $requested->model, $requested, 0);
        if (!$answeredEstimate instanceof Estimate || !$requestedEstimate instanceof Estimate) {
            return null;
        }
        return new Avoided(
            $requestedEstimate,
            $requestedEstimate->co2GramsMin - $answeredEstimate->co2GramsMax,
            $requestedEstimate->co2GramsMax - $answeredEstimate->co2GramsMin,
        );
    }

    private function estimateFromModel(
        string $provider,
        int $outputTokens,
        string $modelName,
        ProviderConfiguration $configuration,
        int $durationMs,
    ): Estimate|Unavailable {
        if ($outputTokens <= 0) {
            return Unavailable::NoOutputTokens;
        }

        $model = $this->findModel($provider, $modelName);
        $providerSettings = $this->data->getProvider($provider);
        if ($model === null || $providerSettings === null) {
            return Unavailable::UnknownModel;
        }

        [$activeMin, $activeMax, $totalMin, $totalMax] = self::parameterRange($model['architecture'] ?? []);
        if ($activeMax <= 0.0 || $totalMax <= 0.0) {
            return Unavailable::UnknownModel;
        }
        [$pueMin, $pueMax] = self::range($providerSettings['pue']);
        $tps = isset($model['deployment']['tps']) ? (float)$model['deployment']['tps'] : null;
        $ttft = isset($model['deployment']['ttft']) ? (float)$model['deployment']['ttft'] : null;
        $requestLatency = $durationMs > 0 ? $durationMs / 1000 : INF;

        $low = $this->itEnergy($activeMin, $totalMin, $outputTokens, $requestLatency, $tps, $ttft);
        $high = $this->itEnergy($activeMax, $totalMax, $outputTokens, $requestLatency, $tps, $ttft);
        $energyWhMin = $low['kWh'] * $pueMin * 1000;
        $energyWhMax = $high['kWh'] * $pueMax * 1000;

        [$gridIntensity, $gridSource] = $this->resolveGridIntensity($configuration, (string)$providerSettings['location']);
        if ($gridIntensity === null) {
            return Unavailable::NoDataSource;
        }

        return new Estimate(
            Method::ModelEstimate,
            $energyWhMin,
            $energyWhMax,
            $energyWhMin / 1000 * $gridIntensity,
            $energyWhMax / 1000 * $gridIntensity,
            [
                'method' => Method::ModelEstimate->value,
                'provider' => $provider,
                'model' => $model['name'] ?? $modelName,
                'activeParameters' => ['min' => $activeMin, 'max' => $activeMax],
                'totalParameters' => ['min' => $totalMin, 'max' => $totalMax],
                'gpus' => ['min' => $low['gpus'], 'max' => $high['gpus']],
                'pue' => ['min' => $pueMin, 'max' => $pueMax],
                'outputTokens' => $outputTokens,
                'generationSeconds' => ['min' => $low['seconds'], 'max' => $high['seconds']],
                // Hardware manufacturing share, reported next to the usage-phase CO2e, not included in it
                'embodiedCo2Grams' => ['min' => $low['embodiedGrams'], 'max' => $high['embodiedGrams']],
                'gridIntensity' => $gridIntensity,
                'gridSource' => $gridSource,
                'warnings' => array_values(array_filter((array)($model['warnings'] ?? []), 'is_string')),
                'dataVersion' => $this->data->getVersion(),
            ],
        );
    }

    private function estimateFromDuration(
        float $watts,
        TextResponse $response,
        ProviderConfiguration $configuration,
        int $durationMs,
        bool $streamed,
    ): Estimate|Unavailable {
        // The duration of a streamed request covers the whole PHP request,
        // not just inference, so it can't be attributed to the model.
        if ($streamed) {
            return Unavailable::StreamedLocalRequest;
        }

        [$seconds, $durationSource] = self::inferenceSeconds($response->usage->rawUsage, $durationMs);
        if ($seconds <= 0.0) {
            return Unavailable::NoDuration;
        }

        [$gridIntensity, $gridSource] = $this->resolveGridIntensity($configuration, self::LOCAL_DEFAULT_ZONE);
        if ($gridIntensity === null) {
            return Unavailable::NoDataSource;
        }
        $energyWh = $watts * $seconds / 3600;
        $co2Grams = $energyWh / 1000 * $gridIntensity;

        return new Estimate(
            Method::DurationCalibrated,
            $energyWh,
            $energyWh,
            $co2Grams,
            $co2Grams,
            [
                'method' => Method::DurationCalibrated->value,
                'powerDrawWatts' => $watts,
                'inferenceSeconds' => $seconds,
                'durationSource' => $durationSource,
                'gridIntensity' => $gridIntensity,
                'gridSource' => $gridSource,
            ],
        );
    }

    /**
     * Exact match first, since dated names can have their own entries,
     * then the name without a date suffix.
     *
     * @return array<string, mixed>|null
     */
    private function findModel(string $provider, string $modelName): ?array
    {
        $model = $this->data->findModel($provider, $modelName);
        if ($model !== null) {
            return $model;
        }
        $undated = (string)preg_replace('/-(\d{4}-\d{2}-\d{2}|\d{8})$/', '', $modelName);
        return $undated !== $modelName ? $this->data->findModel($provider, $undated) : null;
    }

    /**
     * IT energy of one inference in kWh (GPUs plus their share of the server), before PUE.
     *
     * @return array{kWh: float, gpus: int, seconds: float, embodiedGrams: float}
     */
    private function itEnergy(
        float $activeParameters,
        float $totalParameters,
        int $outputTokens,
        float $requestLatency,
        ?float $tps,
        ?float $ttft,
    ): array {
        $gpuEnergyPerToken = (self::GPU_ENERGY_ALPHA * exp(self::GPU_ENERGY_BETA * self::BATCH_SIZE) * $activeParameters
            + self::GPU_ENERGY_GAMMA) / 1000;
        $gpuEnergy = $outputTokens * $gpuEnergyPerToken;

        $latencyPerToken = $tps === null || $tps <= 0.0
            ? self::LATENCY_ALPHA * $activeParameters + self::LATENCY_BETA * self::BATCH_SIZE + self::LATENCY_GAMMA
            : 1 / $tps;
        $generationLatency = min($requestLatency, $outputTokens * $latencyPerToken + ($ttft ?? 0.0));

        $requiredMemory = 1.2 * $totalParameters * self::MODEL_QUANTIZATION_BITS / 8;
        $gpus = (int)(2 ** (int)ceil(log(max(1, (int)ceil($requiredMemory / self::GPU_MEMORY_GB)), 2)));

        $serverEnergy = ($generationLatency / 3600) * self::SERVER_POWER_KW * ($gpus / self::SERVER_GPUS) / self::BATCH_SIZE;

        $embodiedKg = $generationLatency
            * (($gpus / self::SERVER_GPUS) * self::SERVER_EMBODIED_GWP_KG + $gpus * self::GPU_EMBODIED_GWP_KG)
            / (self::HARDWARE_LIFESPAN_SECONDS * self::BATCH_SIZE);

        return [
            'kWh' => $serverEnergy + $gpus * $gpuEnergy,
            'gpus' => $gpus,
            'seconds' => $generationLatency,
            'embodiedGrams' => $embodiedKg * 1000,
        ];
    }

    /**
     * @return array{0: ?float, 1: string} intensity in gCO2e/kWh and where it came from
     */
    private function resolveGridIntensity(ProviderConfiguration $configuration, string $defaultZone): array
    {
        // Zero is a valid setting, unlike an empty field
        $configured = $configuration->get('grid_intensity');
        if (is_numeric($configured) && (float)$configured >= 0.0) {
            return [(float)$configured, 'configuration'];
        }
        return [$this->data->getGridIntensity($defaultZone), $defaultZone];
    }

    private function isSupported(AiRequestInterface $request): bool
    {
        foreach (self::SUPPORTED_REQUESTS as $className) {
            if ($request instanceof $className) {
                return true;
            }
        }
        return false;
    }

    /**
     * Output tokens including reasoning, by what the answering provider reports.
     */
    private static function outputTokens(AiUsageStatistics $usage, ProviderConfiguration $answered): int
    {
        if (in_array($answered->providerIdentifier, self::REASONING_REPORTED_SEPARATELY, true)) {
            return $usage->completionTokens + $usage->reasoningTokens;
        }
        return $usage->completionTokens;
    }

    /**
     * Inference time without model loading, from the provider's own timings
     * (Ollama, in nanoseconds) when present, else the measured request duration.
     *
     * @param array<string, mixed> $rawUsage
     * @return array{0: float, 1: string}
     */
    private static function inferenceSeconds(array $rawUsage, int $durationMs): array
    {
        $total = $rawUsage['total_duration'] ?? null;
        if (is_int($total) && $total > 0) {
            $load = $rawUsage['load_duration'] ?? 0;
            return [max(0, $total - (is_int($load) ? $load : 0)) / 1e9, 'provider_timings'];
        }
        return [max(0, $durationMs) / 1000, 'request_duration'];
    }

    /**
     * @param array<string, mixed> $architecture
     * @return array{0: float, 1: float, 2: float, 3: float} active min/max, total min/max (billions)
     */
    private static function parameterRange(array $architecture): array
    {
        $parameters = $architecture['parameters'] ?? null;
        if (($architecture['type'] ?? '') === 'moe' && is_array($parameters)) {
            [$activeMin, $activeMax] = self::range($parameters['active'] ?? 0);
            [$totalMin, $totalMax] = self::range($parameters['total'] ?? 0);
            return [$activeMin, $activeMax, $totalMin, $totalMax];
        }
        [$min, $max] = self::range($parameters ?? 0);
        return [$min, $max, $min, $max];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function range(mixed $value): array
    {
        if (is_array($value)) {
            return [(float)($value['min'] ?? 0), (float)($value['max'] ?? 0)];
        }
        return [(float)$value, (float)$value];
    }

    private static function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float)$value > 0 ? (float)$value : null;
    }
}
