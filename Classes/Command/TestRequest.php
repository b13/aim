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
use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Capability\TranslationCapableInterface;
use B13\Aim\Exception\ProviderNotFoundException;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\Message\UserMessage;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Request\TranslationRequest;
use B13\Aim\Response\TextResponse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Sends a one-off (non-streaming) AI request and prints the response, usage,
 * timing, and whether a request-log row was written. A quick way to test a
 * provider configuration from the CLI without wiring up a consuming extension.
 *
 * By default the provider is resolved from the database (Admin Tools > AiM >
 * Providers). With --site, it is resolved from a site's settings.yaml instead
 * and dispatched through the pipeline directly.
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
        private readonly ProviderResolver $resolver,
        private readonly AiMiddlewarePipeline $pipeline,
        private readonly SiteFinder $siteFinder,
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
                . '  vendor/bin/typo3 aim:test embed --prompt "vector me"' . PHP_EOL
                . '  vendor/bin/typo3 aim:test text --site main --prompt "Resolve from site settings"'
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
                'site',
                null,
                InputOption::VALUE_REQUIRED,
                'Resolve the provider from this site\'s settings.yaml instead of the database. Takes precedence over --provider.',
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
        $site = (string)$input->getOption('site');
        $systemPrompt = (string)$input->getOption('system-prompt');
        $maxTokens = (int)$input->getOption('max-tokens');

        $io->title('AiM request test');
        $io->writeln(sprintf(' Capability: <info>%s</info>', $capability));
        if ($site !== '') {
            $io->writeln(sprintf(' Source:     <info>site settings of "%s"</info>', $site));
        } else {
            $io->writeln(sprintf(' Provider:   <info>%s</info>', $provider !== '' ? $provider : '(default)'));
        }
        $io->writeln(sprintf(' Prompt:     <info>%s</info>', $prompt));
        if ($systemPrompt !== '' && $capability !== 'embed') {
            $io->writeln(sprintf(' System:     <info>%s</info>', $systemPrompt));
        }
        $io->newLine();

        $logCountBefore = $this->countLogRows();
        $start = hrtime(true);

        try {
            $response = $site !== ''
                ? $this->sendViaSite($capability, $site, $input, $prompt, $systemPrompt, $maxTokens)
                : $this->sendRequest($capability, $input, $prompt, $systemPrompt, $maxTokens, $provider);
        } catch (ProviderNotFoundException $e) {
            if ($site !== '') {
                // Site-settings path: the exception message is already specific
                // (e.g. the configured provider's bridge is not installed).
                $io->error('Request failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
            $io->error('No AI provider is available for the "' . $capability . '" capability.');
            $io->writeln(' AiM needs at least one provider configuration before it can send requests.');
            $io->writeln(' Create one in the TYPO3 backend under <info>Admin Tools > AiM > Providers</info>:');
            $io->newLine();
            $io->listing([
                'Pick a provider (auto-populated from installed Symfony AI bridges)',
                'Enter your API key — or an endpoint URL such as http://localhost:11434 for Ollama',
                'Select a model that supports the capability you want to test',
            ]);
            $io->writeln(' Or point --site at a site whose settings.yaml configures an AI provider.');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Request failed: ' . $e->getMessage());
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

    /**
     * Resolve the provider from a site's settings.yaml and dispatch directly
     * through the pipeline. The Ai facade only resolves database-backed
     * configurations, so site-settings configs need this explicit path.
     */
    private function sendViaSite(
        string $capability,
        string $siteIdentifier,
        InputInterface $input,
        string $prompt,
        string $systemPrompt,
        int $maxTokens,
    ): TextResponse {
        $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);

        $capabilityFqcn = match ($capability) {
            'conversation' => ConversationCapableInterface::class,
            'translate' => TranslationCapableInterface::class,
            'embed' => EmbeddingCapableInterface::class,
            default => TextGenerationCapableInterface::class,
        };

        $resolved = $this->resolver->resolveFromSiteSettings($capabilityFqcn, $site);
        $configuration = $resolved->configuration;
        $metadata = ['extension' => 'aim'];

        $request = match ($capability) {
            'conversation' => new ConversationRequest(
                configuration: $configuration,
                messages: [new UserMessage($prompt)],
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                metadata: $metadata,
            ),
            'translate' => new TranslationRequest(
                configuration: $configuration,
                text: $prompt,
                sourceLanguage: (string)$input->getOption('from'),
                targetLanguage: (string)$input->getOption('to'),
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                metadata: $metadata,
            ),
            'embed' => new EmbeddingRequest(
                configuration: $configuration,
                input: [$prompt],
                metadata: $metadata,
            ),
            default => new TextGenerationRequest(
                configuration: $configuration,
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                metadata: $metadata,
            ),
        };

        return $this->pipeline->dispatch($request, $resolved);
    }

    private function countLogRows(): int
    {
        return $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->count('*', self::TABLE, []);
    }
}
