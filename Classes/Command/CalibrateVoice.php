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

use B13\Aim\Domain\Repository\PagePromptFragmentRepository;
use B13\Aim\Prompt\PromptFragmentScope;
use B13\Aim\Service\SiteVoiceCalibrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Crawls a site's root page + a bounded slice of its subpages (see
 * PageTreeResolver::resolveBoundedSlice()), derives a voice instruction +
 * examples from the combined real content (SiteVoiceCalibrator), and saves
 * it as an auto-calibrated tx_aim_prompt_fragment on the site's root page,
 * viewable/editable via the Prompt Preview module (and, per the originating
 * request, meant to also surface in a future T3ppy backend module reading
 * the same table).
 *
 * Runs for every configured site by default; --page narrows to one root
 * page uid for testing/targeted re-runs. Schedulable as-is via TYPO3's
 * built-in "Execute console command" Scheduler task; no dedicated Task
 * class needed for that.
 */
#[AsCommand(
    name: 'aim:calibrateVoice',
    description: 'Crawl a site\'s pages and calibrate its voice, saving the result as a prompt fragment on its root page.',
)]
final class CalibrateVoice extends Command
{
    private const DEFAULT_MAX_PAGES = 25;
    private const DEFAULT_MAX_DEPTH = 2;
    private const FRAGMENT_TITLE = 'Voice (auto-calibrated)';

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SiteVoiceCalibrator $voiceCalibrator,
        private readonly PagePromptFragmentRepository $fragmentRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'For each site (or just the one given via --page), crawls the root page and a '
                . 'bounded slice of its subpages, asks AiM to calibrate a voice instruction '
                . 'and examples from the combined real content, and saves the result as an '
                . 'auto-calibrated prompt fragment on the site\'s root page; re-running this '
                . 'command refreshes that same fragment rather than creating another one.'
            )
            ->addOption(
                'page',
                null,
                InputOption::VALUE_REQUIRED,
                'Only process this root page uid, instead of every configured site.',
            )
            ->addOption(
                'max-pages',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of pages to crawl per site.',
                (string)self::DEFAULT_MAX_PAGES,
            )
            ->addOption(
                'max-depth',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum tree depth below the root page to crawl (0 = root page only).',
                (string)self::DEFAULT_MAX_DEPTH,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Calibrate and print the result without saving a fragment.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxPages = max(1, (int)$input->getOption('max-pages'));
        $maxDepth = max(0, (int)$input->getOption('max-depth'));
        $dryRun = (bool)$input->getOption('dry-run');

        $rawPage = $input->getOption('page');
        $rootPageIds = $rawPage !== null ? [(int)$rawPage] : $this->resolveAllSiteRootPageIds();

        if ($rootPageIds === []) {
            $output->writeln('<comment>No site root pages found to process.</comment>');
            return Command::SUCCESS;
        }

        $exitCode = Command::SUCCESS;
        foreach ($rootPageIds as $rootPageId) {
            $output->writeln('');
            $output->writeln(sprintf('<info>Page %d</info>', $rootPageId));

            $result = $this->voiceCalibrator->calibrateForRootPage($rootPageId, $maxDepth, $maxPages);
            if (!$result['ok']) {
                $output->writeln(sprintf('<error>%s</error>', $result['message']));
                $exitCode = Command::FAILURE;
                continue;
            }

            $output->writeln('Pages used: ' . implode(', ', $result['pagesUsed']));
            $output->writeln('Tone: ' . $result['tone']);
            $output->writeln('Examples: ' . $result['examples']);

            if ($dryRun) {
                $output->writeln('<comment>[dry-run] Nothing saved.</comment>');
                continue;
            }

            $fragmentUid = $this->fragmentRepository->upsertAutoDetectedFragment(
                $rootPageId,
                self::FRAGMENT_TITLE,
                $result['tone'],
                $result['examples'],
                array_map(static fn(PromptFragmentScope $scope): string => $scope->value, PromptFragmentScope::cases()),
            );
            $output->writeln(sprintf('<info>Saved fragment uid %d on page %d.</info>', $fragmentUid, $rootPageId));
        }

        return $exitCode;
    }

    /**
     * @return list<int>
     */
    private function resolveAllSiteRootPageIds(): array
    {
        return array_values(array_unique(array_map(
            static fn($site): int => $site->getRootPageId(),
            $this->siteFinder->getAllSites(),
        )));
    }
}
