<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Command;

use B13\Aim\Command\GradePendingLogs;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Grading\GradeLabel;
use B13\Aim\Grading\GradeStatus;
use B13\Aim\Service\GradingService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class GradePendingLogsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    #[Test]
    public function picksUpPendingRowsOlderThanMinAge(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $now = time();

        // Old pending row — should be graded
        $oldUid = $logRepo->log([
            'crdate' => $now - 120,
            'configuration_uid' => 1,
            'grade_status' => GradeStatus::Pending->value,
        ]);
        // Fresh pending row — should be skipped (within min-age window)
        $freshUid = $logRepo->log([
            'crdate' => $now - 5,
            'configuration_uid' => 1,
            'grade_status' => GradeStatus::Pending->value,
        ]);

        $gradedUids = [];
        $stubGrader = $this->stubGradingService(function (int $uid) use (&$gradedUids, $logRepo): void {
            $gradedUids[] = $uid;
            $logRepo->updateGrade($uid, 0.8, GradeLabel::Good, 'test', 'gpt-4o-mini', 0.0001, 12);
        });

        $command = new GradePendingLogs($logRepo, $stubGrader);
        $tester = new CommandTester($command);
        $tester->execute(['--min-age' => '60', '--limit' => '10']);

        self::assertSame([$oldUid], $gradedUids);
        self::assertSame(0, $tester->getStatusCode());

        $oldRow = $logRepo->findByUid($oldUid);
        self::assertSame(GradeStatus::Done->value, $oldRow['grade_status']);

        $freshRow = $logRepo->findByUid($freshUid);
        self::assertSame(GradeStatus::Pending->value, $freshRow['grade_status']);
    }

    #[Test]
    public function reportsNoPendingRowsWhenEmpty(): void
    {
        $logRepo = $this->get(RequestLogRepository::class);
        $stubGrader = $this->stubGradingService(static function () {});

        $command = new GradePendingLogs($logRepo, $stubGrader);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No pending grades', $tester->getDisplay());
    }

    private function stubGradingService(\Closure $onGrade): GradingService
    {
        return new class($onGrade) extends GradingService {
            // @phpstan-ignore-next-line — overriding constructor on purpose
            public function __construct(private readonly \Closure $onGrade) {}

            public function grade(int $logUid): void
            {
                ($this->onGrade)($logUid);
            }
        };
    }
}
