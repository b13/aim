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

use B13\Aim\Ai;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Response\TextResponse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Sends a one-off (non-streaming) AI request through the AiM facade and
 * prints the response, usage, timing, and whether a request-log row was
 * written. A quick way to test a provider configuration from the CLI
 * without wiring up a consuming extension.
 */
#[AsCommand(
    name: 'aim:test',
    description: 'Send a one-off AI request (text, conversation, translate, embed) and report the result.',
)]
final class TestRequest extends Command
{
    private const TABLE = 'tx_aim_request_log';

    private const CAPABILITIES = ['text', 'conversation', 'translate', 'embed'];

    public function __construct(
        private readonly Ai $ai,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Examples:' . PHP_EOL
                . '  vendor/bin/typo3 aim:test text --prompt "Write a haiku about TYPO3"' . PHP_EOL
                . '  vendor/bin/typo3 aim:test conversation -p "anthropic:*" --prompt "Hi there"' . PHP_EOL
                . '  vendor/bin/typo3 aim:test translate --prompt "Hello world" --from English --to German' . PHP_EOL
                . '  vendor/bin/typo3 aim:test embed --prompt "vector me"'
            )
            ->addArgument(
                'capability',
                InputArgument::OPTIONAL,
                'One of: ' . implode(', ', self::CAPABILITIES) . '.',
                'text',
            )
            ->addOption(
                'prompt',
                null,
                InputOption::VALUE_REQUIRED,
                'Prompt / text to send.',
                'Write one short sentence about TYPO3.',
            )
            ->addOption(
                'provider',
                'p',
                InputOption::VALUE_REQUIRED,
                'Provider notation (e.g. "openai:gpt-4o", "anthropic:*"). Defaults to the configured default.',
                '',
            )
            ->addOption(
                'system-prompt',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional system prompt (ignored for embed).',
                '',
            )
            ->addOption(
                'max-tokens',
                null,
                InputOption::VALUE_REQUIRED,
                'Max tokens to generate (ignored for embed).',
                '300',
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Source language (translate only).',
                'English',
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                'Target language (translate only).',
                'German',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $capability = strtolower((string)$input->getArgument('capability'));
        if (!in_array($capability, self::CAPABILITIES, true)) {
            $io->error(sprintf('Unknown capability "%s". Use one of: %s.', $capability, implode(', ', self::CAPABILITIES)));
            return Command::INVALID;
        }

        $prompt = (string)$input->getOption('prompt');
        $provider = (string)$input->getOption('provider');
        $systemPrompt = (string)$input->getOption('system-prompt');
        $maxTokens = (int)$input->getOption('max-tokens');

        $io->title('AiM request test');
        $io->writeln(sprintf(' Capability: <info>%s</info>', $capability));
        $io->writeln(sprintf(' Provider:   <info>%s</info>', $provider !== '' ? $provider : '(default)'));
        $io->writeln(sprintf(' Prompt:     <info>%s</info>', $prompt));
        if ($systemPrompt !== '' && $capability !== 'embed') {
            $io->writeln(sprintf(' System:     <info>%s</info>', $systemPrompt));
        }
        $io->newLine();

        $logCountBefore = $this->countLogRows();
        $start = hrtime(true);

        try {
            $response = $this->sendRequest($capability, $input, $prompt, $systemPrompt, $maxTokens, $provider);
        } catch (\Throwable $e) {
            $io->error($capability . '() threw: ' . $e::class . ' — ' . $e->getMessage());
            return Command::FAILURE;
        }

        $durationMs = (int)((hrtime(true) - $start) / 1_000_000);

        if ($response->errors !== []) {
            $io->error('Response carries errors: ' . implode(' | ', $response->errors));
            return Command::FAILURE;
        }

        $io->writeln('<comment>--- response ---</comment>');
        $io->writeln($response->content !== '' ? $response->content : '<comment>(empty content)</comment>');
        $io->writeln('<comment>--- end ---</comment>');
        $io->newLine();

        $usage = $response->usage;
        $io->writeln(sprintf('Model used:      <info>%s</info>', $usage->modelUsed !== '' ? $usage->modelUsed : '(unknown)'));
        $io->writeln(sprintf(
            'Usage:           prompt=<info>%d</info>, completion=<info>%d</info>, total=<info>%d</info>',
            $usage->promptTokens,
            $usage->completionTokens,
            $usage->getTotalTokens(),
        ));
        $io->writeln(sprintf('Cost:            <info>%.6f</info>', $usage->cost));
        $io->writeln(sprintf('Wall time:       <info>%d ms</info>', $durationMs));

        $delta = $this->countLogRows() - $logCountBefore;
        $io->writeln(sprintf('Log rows delta:  <comment>%+d</comment>', $delta));

        if ($delta === 0) {
            $io->newLine();
            $io->warning('No request-log row was written — check the configuration\'s privacy level.');
        }

        $io->newLine();
        $io->success('Request completed.');
        return Command::SUCCESS;
    }

    private function sendRequest(
        string $capability,
        InputInterface $input,
        string $prompt,
        string $systemPrompt,
        int $maxTokens,
        string $provider,
    ): TextResponse {
        return match ($capability) {
            'conversation' => $this->ai->conversation(
                messages: [new UserMessage($prompt)],
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                extensionKey: 'aim',
                provider: $provider,
            ),
            'translate' => $this->ai->translate(
                text: $prompt,
                sourceLanguage: (string)$input->getOption('from'),
                targetLanguage: (string)$input->getOption('to'),
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                extensionKey: 'aim',
                provider: $provider,
            ),
            'embed' => $this->ai->embed(
                input: $prompt,
                extensionKey: 'aim',
                provider: $provider,
            ),
            default => $this->ai->text(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                extensionKey: 'aim',
                provider: $provider,
            ),
        };
    }

    private function countLogRows(): int
    {
        return $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->count('*', self::TABLE, []);
    }
}
