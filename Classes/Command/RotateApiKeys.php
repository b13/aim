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

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Exception\ApiKeyEncryptionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Re-encrypts stored AiM provider API keys after $TYPO3_CONF_VARS[SYS][encryptionKey]
 * has been rotated.
 *
 * Workflow per row:
 *   1. Skip plaintext / endpoint URL / unencrypted value
 *   2. Decrypts with the current system key, skip already migrated
 *   3. Decrypts with the supplied --old-key, re-encrypt with the current system key
 *   4. Decrypts with neither is reported as unrecoverable, abort without writes
 *
 * Idempotent: a second run with the same --old-key is a no-op.
 */
#[AsCommand(
    name: 'aim:rotateApiKeys',
    description: 'Re-encrypt stored AiM API keys after a SYS/encryptionKey rotation.',
)]
final class RotateApiKeys extends Command
{
    private const TABLE = 'tx_aim_configuration';

    public function __construct(
        private readonly ApiKeyEncryption $encryption,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'When $TYPO3_CONF_VARS[SYS][encryptionKey] has been rotated, existing encrypted '
                . 'API keys can no longer be read. This command takes the previous value of the '
                . 'system key, decrypts each stored API key with it, and re-encrypts using the '
                . 'current system key.'
            )
            ->addOption(
                'old-key',
                null,
                InputOption::VALUE_REQUIRED,
                'The previous value of $TYPO3_CONF_VARS[SYS][encryptionKey].',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would change without writing.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $oldKey = (string)$input->getOption('old-key');
        if ($oldKey === '') {
            $output->writeln('<error>--old-key is required.</error>');
            return Command::FAILURE;
        }
        $dryRun = (bool)$input->getOption('dry-run');

        [$toRotate, $unrecoverable, $alreadyCurrent, $unencrypted] = $this->classifyRows($oldKey);

        if ($unrecoverable !== []) {
            $output->writeln('<error>The following rows cannot be decrypted with the supplied old key:</error>');
            foreach ($unrecoverable as $uid => $message) {
                $output->writeln(sprintf('  - uid=%d: %s', $uid, $message));
            }
            $output->writeln('<error>Aborting without writes. Verify the --old-key value.</error>');
            return Command::FAILURE;
        }

        if ($toRotate === []) {
            $output->writeln('Nothing to rotate.');
            $this->writeSkipBreakdown($output, $alreadyCurrent, $unencrypted);
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                '<info>[dry-run] Would re-encrypt %d row(s).</info>',
                count($toRotate),
            ));
            $this->writeSkipBreakdown($output, $alreadyCurrent, $unencrypted);
            return Command::SUCCESS;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($toRotate as $uid => $plaintext) {
            $connection->update(
                self::TABLE,
                ['api_key' => $this->encryption->encrypt($plaintext)],
                ['uid' => $uid],
                ['api_key' => Connection::PARAM_STR],
            );
        }

        $output->writeln(sprintf(
            '<info>Re-encrypted %d API key(s).</info>',
            count($toRotate),
        ));
        $this->writeSkipBreakdown($output, $alreadyCurrent, $unencrypted);
        return Command::SUCCESS;
    }

    private function writeSkipBreakdown(OutputInterface $output, int $alreadyCurrent, int $unencrypted): void
    {
        if ($alreadyCurrent > 0) {
            $output->writeln(sprintf(
                '  - %d row(s) already use the current system key',
                $alreadyCurrent,
            ));
        }
        if ($unencrypted > 0) {
            $output->writeln(sprintf(
                '  - %d row(s) are not encrypted (endpoint URLs or empty values)',
                $unencrypted,
            ));
        }
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>, 2: int, 3: int}
     *         [uid => plaintext to re-encrypt], [uid => failure message], already-current count, unencrypted count
     */
    private function classifyRows(string $oldKey): array
    {
        $toRotate = [];
        $unrecoverable = [];
        $alreadyCurrent = 0;
        $unencrypted = 0;

        foreach ($this->fetchAllRows() as $row) {
            $uid = (int)$row['uid'];
            $value = (string)$row['api_key'];

            if ($value === '' || $this->encryption->isEndpointUrl($value) || !$this->encryption->isEncrypted($value)) {
                $unencrypted++;
                continue;
            }

            try {
                $this->encryption->decrypt($value);
                $alreadyCurrent++;
                continue;
            } catch (ApiKeyEncryptionException) {
                // Fall through to old-key attempt
            }

            try {
                $toRotate[$uid] = $this->encryption->decryptWithSystemKey($value, $oldKey);
            } catch (ApiKeyEncryptionException $e) {
                $unrecoverable[$uid] = $e->getMessage();
            }
        }

        return [$toRotate, $unrecoverable, $alreadyCurrent, $unencrypted];
    }

    /**
     * @return list<array{uid: int|string, api_key: string|null}>
     */
    private function fetchAllRows(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        return $qb->select('uid', 'api_key')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
