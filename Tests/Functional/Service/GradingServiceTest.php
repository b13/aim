<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.RequestLogRepository.php
 */

namespace B13\Aim\Tests\Functional\Service;

use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Grading\GradeLabel;
use B13\Aim\Grading\GradeStatus;
use B13\Aim\Service\GradingService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class GradingServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function repositoryUpdateGradePersistsAllFields(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $uid = $logRepo->log([
            'crdate' => time(),
            'configuration_uid' => 1,
            'grade_status' => 'pending',
        ]);

        $logRepo->updateGrade(
            $uid,
            score: 0.83,
            label: GradeLabel::Good,
            reason: 'Mostly correct, minor omissions.',
            judgeModel: 'gpt-4o-mini',
            judgeCost: 0.000123,
            durationMs: 280,
        );

        $row = $logRepo->findByUid($uid);
        self::assertSame(GradeStatus::Done->value, $row['grade_status']);
        self::assertEqualsWithDelta(0.83, (float)$row['grade_score'], 0.001);
        self::assertSame('good', $row['grade_label']);
        self::assertStringContainsString('omissions', $row['grade_reason']);
        self::assertSame('gpt-4o-mini', $row['judge_model']);
        self::assertEqualsWithDelta(0.000123, (float)$row['judge_cost'], 1e-6);
        self::assertSame(280, (int)$row['grade_duration_ms']);
        self::assertSame('', $row['grade_error']);
    }

    #[Test]
    public function repositoryMarkGradeFailedClampsErrorLength(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $uid = $logRepo->log([
            'crdate' => time(),
            'configuration_uid' => 1,
            'grade_status' => 'pending',
        ]);

        $logRepo->markGradeFailed($uid, str_repeat('x', 800));

        $row = $logRepo->findByUid($uid);
        self::assertSame(GradeStatus::Failed->value, $row['grade_status']);
        self::assertSame(500, mb_strlen($row['grade_error']));
    }

    #[Test]
    public function findPendingGradesRespectsAgeFilter(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $now = time();

        $oldUid = $logRepo->log([
            'crdate' => $now - 600,
            'configuration_uid' => 1,
            'grade_status' => 'pending',
        ]);
        $logRepo->log([
            'crdate' => $now - 10,
            'configuration_uid' => 1,
            'grade_status' => 'pending',
        ]);
        $logRepo->log([
            'crdate' => $now - 600,
            'configuration_uid' => 1,
            'grade_status' => 'done',
        ]);

        $found = $logRepo->findPendingGrades(60, 100);
        self::assertCount(1, $found);
        self::assertSame($oldUid, (int)$found[0]['uid']);

        $pendingCount = $logRepo->countPendingGradesOlderThan(60);
        self::assertSame(1, $pendingCount);
    }

    #[Test]
    public function parseJudgeOutputAcceptsCleanJson(): void
    {
        $parsed = $this->invokeParser('{"score": 0.75, "label": "good", "reason": "ok"}');
        self::assertSame(0.75, $parsed['score']);
        self::assertSame(GradeLabel::Good, $parsed['label']);
        self::assertSame('ok', $parsed['reason']);
    }

    #[Test]
    public function parseJudgeOutputStripsMarkdownFences(): void
    {
        $parsed = $this->invokeParser("```json\n{\"score\": 0.9, \"label\": \"excellent\", \"reason\": \"great\"}\n```");
        self::assertSame(0.9, $parsed['score']);
        self::assertSame(GradeLabel::Excellent, $parsed['label']);
    }

    #[Test]
    public function parseJudgeOutputClampsOutOfRangeScores(): void
    {
        $tooHigh = $this->invokeParser('{"score": 1.5, "label": "excellent", "reason": "x"}');
        self::assertSame(1.0, $tooHigh['score']);

        $tooLow = $this->invokeParser('{"score": -0.3, "label": "poor", "reason": "x"}');
        self::assertSame(0.0, $tooLow['score']);
    }

    #[Test]
    public function parseJudgeOutputBackfillsLabelFromScoreWhenInvalid(): void
    {
        $parsed = $this->invokeParser('{"score": 0.7, "label": "meh", "reason": "x"}');
        self::assertSame(GradeLabel::Good, $parsed['label']);
    }

    #[Test]
    public function parseJudgeOutputReturnsNullOnMalformedJson(): void
    {
        self::assertNull($this->invokeParser('not json at all'));
        self::assertNull($this->invokeParser('{"missing": "score"}'));
    }

    private function invokeParser(string $raw): ?array
    {
        $service = $this->get(GradingService::class);
        $method = new \ReflectionMethod($service, 'parseJudgeOutput');
        return $method->invoke($service, $raw);
    }
}
