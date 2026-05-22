<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Domain\Repository;

use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Grading\GradeLabel;
use B13\Aim\Grading\GradeStatus;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RequestLogRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function modelPerformanceProfileAggregatesGradesOverDoneRowsOnly(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);

        // Three graded "done" rows for cheap-model: scores 0.6, 0.8, 1.0 → avg 0.8
        foreach ([0.6, 0.8, 1.0] as $score) {
            $logRepo->log($this->row('cheap-model', 0.5, GradeStatus::Done, $score));
        }
        // One failed and one ungraded row — must be excluded from the grade average
        $logRepo->log($this->row('cheap-model', 0.4, GradeStatus::Failed, 0.0));
        $logRepo->log($this->row('cheap-model', 0.4, GradeStatus::None, 0.0));

        $profiles = $logRepo->getModelPerformanceProfile('TextGenerationRequest');
        $cheap = $this->profileFor($profiles, 'cheap-model');

        self::assertSame(5, $cheap['request_count']);
        self::assertSame(3, $cheap['graded_count']);
        self::assertEqualsWithDelta(0.8, $cheap['avg_grade_score'], 0.0001);
    }

    #[Test]
    public function modelPerformanceProfileReportsZeroGradesForUngradedModel(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $logRepo->log($this->row('ungraded-model', 0.5, GradeStatus::None, 0.0));
        $logRepo->log($this->row('ungraded-model', 0.5, GradeStatus::None, 0.0));

        $profiles = $logRepo->getModelPerformanceProfile('TextGenerationRequest');
        $model = $this->profileFor($profiles, 'ungraded-model');

        self::assertSame(2, $model['request_count']);
        self::assertSame(0, $model['graded_count']);
        self::assertSame(0.0, $model['avg_grade_score']);
    }

    private function row(string $model, float $cost, GradeStatus $status, float $gradeScore): array
    {
        return [
            'crdate' => time(),
            'request_type' => 'TextGenerationRequest',
            'provider_identifier' => 'test',
            'model_used' => $model,
            'success' => 1,
            'cost' => $cost,
            'total_tokens' => 100,
            'grade_status' => $status->value,
            'grade_score' => $gradeScore,
            'grade_label' => $status === GradeStatus::Done ? GradeLabel::fromScore($gradeScore)->value : '',
        ];
    }

    /**
     * @param list<array<string, mixed>> $profiles
     * @return array<string, mixed>
     */
    private function profileFor(array $profiles, string $model): array
    {
        foreach ($profiles as $profile) {
            if ($profile['model_used'] === $model) {
                return $profile;
            }
        }
        self::fail('No performance profile for model "' . $model . '".');
    }
}
