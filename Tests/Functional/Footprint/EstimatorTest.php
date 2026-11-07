<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Footprint;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Footprint\Avoided;
use B13\Aim\Footprint\EcoLogitsData;
use B13\Aim\Footprint\Estimate;
use B13\Aim\Footprint\Estimator;
use B13\Aim\Footprint\Method;
use B13\Aim\Footprint\Unavailable;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\VisionRequest;
use B13\Aim\Response\AiUsageStatistics;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Runs the estimator against the shipped EcoLogits data. Expected values were
 * computed with the EcoLogits Python package at the commit the data file pins.
 */
final class EstimatorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    public static function ecoLogitsReferenceProvider(): \Generator
    {
        yield 'gpt-4o-mini, dense range, short alt text' => ['openai', 'gpt-4o-mini', 60, 2000, 0.004261320094982515, 0.0050812760923682285, 0.0016380514445112787, 0.001953242529906347, 0.00021150613132911396, 0.00021150613132911396];
        yield 'gpt-4o-mini, dense range, longer output' => ['openai', 'gpt-4o-mini', 300, 10000, 0.019619100474912575, 0.02371888046184114, 0.007541582222556395, 0.009117537649531736, 0.0007059681566455696, 0.0007059681566455696];
        yield 'gpt-4o-mini, latency clamped by measured request time' => ['openai', 'gpt-4o-mini', 300, 1000, 0.01701170332301384, 0.021111483309942405, 0.00653929875736652, 0.008115254184341862, 0.00016276041666666666, 0.00016276041666666666];
        yield 'gpt-4o-mini-2024-07-18, dated model name with its own entry' => ['openai', 'gpt-4o-mini-2024-07-18', 60, 2000, 0.004404200783650387, 0.0052241567810361, 0.001692974781235209, 0.0020081658666302768, 0.00024127294146825397, 0.00024127294146825397];
        yield 'gpt-4o, MoE, active range, several GPUs' => ['openai', 'gpt-4o', 200, 5000, 0.29324939497829317, 0.4856657356981406, 0.11272506742965589, 0.18668990880236525, 0.008626829954954954, 0.008626829954954954];
        yield 'claude-haiku-4-5, alias, PUE range' => ['anthropic', 'claude-haiku-4-5', 150, 3000, 0.0092597559525958, 0.024237519534402834, 0.003559450188177826, 0.00931690250902445, 0.00039041897415611815, 0.0007808379483122363];
        yield 'claude-sonnet-4-5, alias, MoE, PUE range' => ['anthropic', 'claude-sonnet-4-5', 150, 5000, 0.2251743032810215, 0.3726000425246935, 0.08655700218122465, 0.14322745634649217, 0.012295386904761906, 0.012295386904761906];
        yield 'gemini-2.5-flash, MoE' => ['gemini', 'gemini-2.5-flash', 100, 2000, 0.13708594425083973, 0.2244750323277704, 0.052695836970022794, 0.08628820242679495, 0.005208333333333333, 0.005208333333333333];
        yield 'mistral-small-latest, no tps, latency regression, SWE grid' => ['mistral', 'mistral-small-latest', 100, 2000, 0.026960917616328947, 0.026960917616328947, 0.0009506419551517587, 0.0009506419551517587, 0.0013020833333333333, 0.0013020833333333333];
        yield 'mistral-large-latest, MoE, SWE grid' => ['mistral', 'mistral-large-latest', 100, 2000, 0.2854382644415763, 0.2854382644415763, 0.01006455320420998, 0.01006455320420998, 0.010416666666666666, 0.010416666666666666];
    }

    #[Test]
    #[DataProvider('ecoLogitsReferenceProvider')]
    public function matchesEcoLogitsReferenceValues(
        string $provider,
        string $model,
        int $outputTokens,
        int $durationMs,
        float $energyMin,
        float $energyMax,
        float $co2Min,
        float $co2Max,
        float $embodiedMin,
        float $embodiedMax,
    ): void {
        $estimate = $this->estimate($provider, $model, new AiUsageStatistics(completionTokens: $outputTokens), $durationMs);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertSame(Method::ModelEstimate, $estimate->method);
        self::assertEqualsWithDelta($energyMin, $estimate->energyWhMin, $energyMin * 1e-9);
        self::assertEqualsWithDelta($energyMax, $estimate->energyWhMax, $energyMax * 1e-9);
        self::assertEqualsWithDelta($co2Min, $estimate->co2GramsMin, $co2Min * 1e-9);
        self::assertEqualsWithDelta($co2Max, $estimate->co2GramsMax, $co2Max * 1e-9);
        self::assertEqualsWithDelta($embodiedMin, $estimate->details['embodiedCo2Grams']['min'], $embodiedMin * 1e-9);
        self::assertEqualsWithDelta($embodiedMax, $estimate->details['embodiedCo2Grams']['max'], $embodiedMax * 1e-9);
    }

    #[Test]
    public function avoidedIsTheRequestedModelsEstimateMinusTheAnswersWithoutMeasuredDuration(): void
    {
        $answered = $this->configuration('openai', 'gpt-4o-mini');
        $response = new TextResponse('Hello.', new AiUsageStatistics(completionTokens: 200, modelUsed: 'gpt-4o-mini-2024-07-18'));
        $request = new TextGenerationRequest(configuration: $answered, prompt: 'Hi');

        $avoided = $this->estimator()->estimateAvoided($request, $response, $answered, $this->configuration('openai', 'gpt-4o'));
        $answer = $this->estimator()->estimate($request, $response, $answered, 0);

        // EcoLogits reference for gpt-4o, 200 output tokens, generation time unclamped
        self::assertInstanceOf(Avoided::class, $avoided);
        self::assertInstanceOf(Estimate::class, $answer);
        self::assertEqualsWithDelta(0.11272506742965589, $avoided->requested->co2GramsMin, 0.11272506742965589 * 1e-9);
        self::assertEqualsWithDelta(0.18668990880236525, $avoided->requested->co2GramsMax, 0.18668990880236525 * 1e-9);
        self::assertSame('gpt-4o', $avoided->requested->details['model']);
        self::assertEqualsWithDelta($avoided->requested->co2GramsMin - $answer->co2GramsMax, $avoided->co2GramsMin, 1e-12);
        self::assertEqualsWithDelta($avoided->requested->co2GramsMax - $answer->co2GramsMin, $avoided->co2GramsMax, 1e-12);
    }

    #[Test]
    public function avoidedUsesTheGridOfTheRequestedConfiguration(): void
    {
        $answered = $this->configuration('openai', 'gpt-4o-mini');
        $response = new TextResponse('Hello.', new AiUsageStatistics(completionTokens: 200, modelUsed: 'gpt-4o-mini'));

        $avoided = $this->estimator()->estimateAvoided(
            new TextGenerationRequest(configuration: $answered, prompt: 'Hi'),
            $response,
            $answered,
            $this->configuration('openai', 'gpt-4o', ['grid_intensity' => '100.00']),
        );

        self::assertInstanceOf(Avoided::class, $avoided);
        self::assertSame(100.0, $avoided->requested->details['gridIntensity']);
        self::assertSame('configuration', $avoided->requested->details['gridSource']);
    }

    /**
     * Reasoning tokens are reported the way the provider that answered does it,
     * whichever provider the request was routed away from.
     */
    #[Test]
    public function avoidedCountsReasoningTokensByTheAnsweringProvider(): void
    {
        $openAi = $this->configuration('openai', 'gpt-4o-mini');
        $gemini = $this->configuration('gemini', 'gemini-2.5-flash');
        $usage = new AiUsageStatistics(completionTokens: 60, reasoningTokens: 40);

        $fromGemini = $this->estimator()->estimateAvoided(
            new TextGenerationRequest(configuration: $openAi, prompt: 'Hi'),
            new TextResponse('Hello.', $usage),
            $openAi,
            $gemini,
        );
        $fromOpenAi = $this->estimator()->estimateAvoided(
            new TextGenerationRequest(configuration: $gemini, prompt: 'Hi'),
            new TextResponse('Hello.', $usage),
            $gemini,
            $openAi,
        );

        self::assertInstanceOf(Avoided::class, $fromGemini);
        self::assertSame(60, $fromGemini->requested->details['outputTokens']);
        self::assertInstanceOf(Avoided::class, $fromOpenAi);
        self::assertSame(100, $fromOpenAi->requested->details['outputTokens']);
    }

    #[Test]
    public function avoidedIsNotEstimatedWithoutModelDataOnBothSides(): void
    {
        $answered = $this->configuration('openai', 'gpt-4o-mini');
        $request = new TextGenerationRequest(configuration: $answered, prompt: 'Hi');
        $response = new TextResponse('Hello.', new AiUsageStatistics(completionTokens: 200));
        $local = $this->configuration('ollama', 'gemma3:4b', ['power_draw_watts' => '26.09']);

        self::assertNull($this->estimator()->estimateAvoided($request, $response, $answered, $this->configuration('ollama', 'gemma3:4b')));
        self::assertNull($this->estimator()->estimateAvoided($request, $response, $answered, $this->configuration('openai', 'unknown-model')));
        self::assertNull($this->estimator()->estimateAvoided($request, $response, $local, $this->configuration('openai', 'gpt-4o')));
        self::assertNull($this->estimator()->estimateAvoided($request, $response, $answered, $this->configuration('openai', 'gpt-4o', ['power_draw_watts' => '30'])));
        self::assertNull($this->estimator()->estimateAvoided($request, new TextResponse('', errors: ['failed']), $answered, $this->configuration('openai', 'gpt-4o')));
    }

    #[Test]
    public function geminiReasoningTokensAreAddedToOutputTokens(): void
    {
        // Gemini reports thoughtsTokenCount separately from candidatesTokenCount
        $estimate = $this->estimate('gemini', 'gemini-2.5-flash', new AiUsageStatistics(completionTokens: 60, reasoningTokens: 40), 2000);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertEqualsWithDelta(0.052695836970022794, $estimate->co2GramsMin, 1e-12);
        self::assertSame(100, $estimate->details['outputTokens']);
    }

    #[Test]
    public function openAiReasoningTokensAreNotCountedTwice(): void
    {
        // OpenAI's output_tokens already include reasoning_tokens
        $estimate = $this->estimate('openai', 'gpt-4o-mini', new AiUsageStatistics(completionTokens: 60, reasoningTokens: 30), 2000);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertEqualsWithDelta(0.0016380514445112787, $estimate->co2GramsMin, 1e-12);
    }

    #[Test]
    public function undatedNameIsTriedWhenTheDatedOneIsUnknown(): void
    {
        $dated = $this->estimate('openai', 'gpt-4.1-mini-2099-01-01', new AiUsageStatistics(completionTokens: 60), 2000);
        $plain = $this->estimate('openai', 'gpt-4.1-mini', new AiUsageStatistics(completionTokens: 60), 2000);

        self::assertInstanceOf(Estimate::class, $dated);
        self::assertInstanceOf(Estimate::class, $plain);
        self::assertSame($plain->co2GramsMax, $dated->co2GramsMax);
    }

    #[Test]
    public function configuredModelIsUsedWhenTheProviderReportsNone(): void
    {
        $estimate = $this->estimate('openai', '', new AiUsageStatistics(completionTokens: 60), 2000, ['model' => 'gpt-4o-mini']);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertSame('gpt-4o-mini', $estimate->details['model']);
    }

    #[Test]
    public function configuredGridIntensityOverridesTheDataCenterDefault(): void
    {
        $estimate = $this->estimate('openai', 'gpt-4o-mini', new AiUsageStatistics(completionTokens: 60), 2000, ['grid_intensity' => '100.00']);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertEqualsWithDelta($estimate->energyWhMin / 1000 * 100, $estimate->co2GramsMin, 1e-15);
        self::assertSame('configuration', $estimate->details['gridSource']);
    }

    #[Test]
    public function configuredGridIntensityOfZeroIsTakenAsSet(): void
    {
        $estimate = $this->estimate('openai', 'gpt-4o-mini', new AiUsageStatistics(completionTokens: 60), 2000, ['grid_intensity' => '0.00']);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertSame(0.0, $estimate->co2GramsMax);
        self::assertGreaterThan(0.0, $estimate->energyWhMax);
        self::assertSame('configuration', $estimate->details['gridSource']);
    }

    #[Test]
    public function configuredPowerDrawTakesPrecedenceOverModelData(): void
    {
        // An OpenAI configuration pointed at a local OpenAI-compatible server
        $estimate = $this->estimate('openai', 'gpt-oss:20b', new AiUsageStatistics(completionTokens: 60), 2000, ['power_draw_watts' => '50', 'endpoint' => 'http://localhost:11434/v1']);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertSame(Method::DurationCalibrated, $estimate->method);
        self::assertEqualsWithDelta(50 * 2 / 3600, $estimate->energyWhMin, 1e-12);
    }

    #[Test]
    public function localEstimateUsesProviderTimingsWithoutModelLoading(): void
    {
        $usage = new AiUsageStatistics(
            completionTokens: 12,
            modelUsed: 'gemma3:4b',
            rawUsage: ['total_duration' => 3_000_000_000, 'load_duration' => 1_000_000_000],
        );
        $estimate = $this->estimate('ollama', 'gemma3:4b', $usage, 9000, ['power_draw_watts' => '36.00']);

        self::assertInstanceOf(Estimate::class, $estimate);
        self::assertSame(Method::DurationCalibrated, $estimate->method);
        // 36 W × 2 s
        self::assertEqualsWithDelta(0.02, $estimate->energyWhMin, 1e-12);
        self::assertSame($estimate->energyWhMin, $estimate->energyWhMax);
        // World average 458.29 gCO2e/kWh
        self::assertEqualsWithDelta(0.02 / 1000 * 458.29, $estimate->co2GramsMin, 1e-12);
        self::assertSame('provider_timings', $estimate->details['durationSource']);
        self::assertSame('WOR', $estimate->details['gridSource']);
    }

    #[Test]
    public function localEstimateFallsBackToTheRequestDuration(): void
    {
        $estimate = $this->estimate('lmstudio', 'qwen', new AiUsageStatistics(completionTokens: 12), 4000, ['power_draw_watts' => '18', 'grid_intensity' => '329.65']);

        self::assertInstanceOf(Estimate::class, $estimate);
        // 18 W × 4 s
        self::assertEqualsWithDelta(0.02, $estimate->energyWhMin, 1e-12);
        self::assertEqualsWithDelta(0.02 / 1000 * 329.65, $estimate->co2GramsMin, 1e-12);
        self::assertSame('request_duration', $estimate->details['durationSource']);
    }

    #[Test]
    public function streamedLocalRequestIsNotEstimated(): void
    {
        $estimate = $this->estimate('ollama', 'gemma3:4b', new AiUsageStatistics(completionTokens: 12), 4000, ['power_draw_watts' => '18'], streamed: true);

        self::assertSame(Unavailable::StreamedLocalRequest, $estimate);
    }

    #[Test]
    public function localProviderWithoutPowerDrawHasNoData(): void
    {
        self::assertSame(
            Unavailable::NoDataSource,
            $this->estimate('ollama', 'gemma3:4b', new AiUsageStatistics(completionTokens: 12), 4000),
        );
    }

    #[Test]
    public function unknownModelHasNoData(): void
    {
        self::assertSame(
            Unavailable::UnknownModel,
            $this->estimate('openai', 'gpt-does-not-exist', new AiUsageStatistics(completionTokens: 60), 2000),
        );
    }

    #[Test]
    public function modelNameIsMatchedPerProvider(): void
    {
        // An OpenAI model name on Mistral must not borrow OpenAI's data
        self::assertSame(
            Unavailable::UnknownModel,
            $this->estimate('mistral', 'gpt-4o-mini', new AiUsageStatistics(completionTokens: 60), 2000),
        );
    }

    #[Test]
    public function responseWithoutOutputTokensHasNoData(): void
    {
        self::assertSame(
            Unavailable::NoOutputTokens,
            $this->estimate('openai', 'gpt-4o-mini', new AiUsageStatistics(promptTokens: 20), 2000),
        );
    }

    #[Test]
    public function failedRequestHasNoData(): void
    {
        $configuration = $this->configuration('openai', 'gpt-4o-mini');
        $request = new TextGenerationRequest(configuration: $configuration, prompt: 'Hi');
        $response = new TextResponse('', new AiUsageStatistics(completionTokens: 60), errors: ['Rate limited']);

        self::assertSame(Unavailable::RequestFailed, $this->estimator()->estimate($request, $response, $configuration, 2000));
        self::assertSame(Unavailable::RequestFailed, $this->estimator()->estimate($request, null, $configuration, 2000));
    }

    #[Test]
    public function embeddingRequestIsNotEstimated(): void
    {
        $configuration = $this->configuration('openai', 'text-embedding-3-small');
        $request = new EmbeddingRequest(configuration: $configuration, input: ['Hello']);
        $response = new TextResponse('[0.1]', new AiUsageStatistics(promptTokens: 2));

        self::assertSame(Unavailable::UnsupportedRequestType, $this->estimator()->estimate($request, $response, $configuration, 200));
    }

    #[Test]
    public function visionRequestIsEstimated(): void
    {
        $configuration = $this->configuration('openai', 'gpt-4o-mini');
        $request = new VisionRequest(configuration: $configuration, imageData: 'aGVsbG8=', mimeType: 'image/png', prompt: 'Describe');
        $response = new TextResponse('A red bicycle.', new AiUsageStatistics(completionTokens: 60));

        self::assertInstanceOf(Estimate::class, $this->estimator()->estimate($request, $response, $configuration, 2000));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function estimate(
        string $provider,
        string $model,
        AiUsageStatistics $usage,
        int $durationMs,
        array $row = [],
        bool $streamed = false,
    ): Estimate|Unavailable {
        if ($model !== '' && $usage->modelUsed === '') {
            $usage = new AiUsageStatistics(
                promptTokens: $usage->promptTokens,
                completionTokens: $usage->completionTokens,
                reasoningTokens: $usage->reasoningTokens,
                modelUsed: $model,
                rawUsage: $usage->rawUsage,
            );
        }
        $configuration = $this->configuration($provider, (string)($row['model'] ?? $model), $row);
        $request = new TextGenerationRequest(configuration: $configuration, prompt: 'Write an alt text.');
        $response = new TextResponse('A red bicycle against a brick wall.', $usage);

        return $this->estimator()->estimate($request, $response, $configuration, $durationMs, $streamed);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function configuration(string $provider, string $model, array $row = []): ProviderConfiguration
    {
        return new ProviderConfiguration(array_merge(['uid' => 1, 'ai_provider' => $provider, 'model' => $model], $row));
    }

    private function estimator(): Estimator
    {
        return new Estimator(new EcoLogitsData());
    }
}
