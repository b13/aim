<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Command;

use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Service\GradingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scheduler safety-net: grade request_log rows that were marked pending but
 * never finished, typically because the request didn't run under PHP-FPM
 * (CLI, daemon, crash) and register_shutdown_function in GraderMiddleware
 * couldn't reach a successful grade.
 *
 * Picks up rows older than --min-age (default 60s) to avoid racing the
 * shutdown handler for live requests. Mark as failed if grading itself
 * errors, so a retry loop is opt-in via --retry-failed (future).
 */
#[AsCommand(
    name: 'aim:grade-pending',
    description: 'Grade tx_aim_request_log rows still marked grade_status=pending.',
)]
final class GradePendingLogs extends Command
{
    public function __construct(
        private readonly RequestLogRepository $logRepository,
        private readonly GradingService $gradingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Grades AiM request log rows where grade_status="pending" and the row is at '
                . 'least --min-age seconds old. Intended as a safety-net behind GraderMiddleware\'s '
                . 'shutdown-function path. Run it from the TYPO3 scheduler every few minutes.'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of rows to grade in this run.',
                '50',
            )
            ->addOption(
                'min-age',
                null,
                InputOption::VALUE_REQUIRED,
                'Only pick rows older than this many seconds (avoid racing the live shutdown handler).',
                '60',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int)$input->getOption('limit'));
        $minAge = max(0, (int)$input->getOption('min-age'));

        $rows = $this->logRepository->findPendingGrades($minAge, $limit);
        if ($rows === []) {
            $output->writeln('No pending grades older than ' . $minAge . 's.');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Grading %d pending row(s).', count($rows)));
        $graded = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            try {
                $this->gradingService->grade($uid);
                $output->writeln('  - graded uid ' . $uid);
                $graded++;
            } catch (\Throwable $e) {
                $output->writeln(sprintf('  - <error>uid %d failed: %s</error>', $uid, $e->getMessage()));
                $failed++;
            }
        }

        $output->writeln(sprintf('<info>Done: %d graded, %d failed.</info>', $graded, $failed));
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
