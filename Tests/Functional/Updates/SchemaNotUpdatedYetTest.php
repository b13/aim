<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Updates;

use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Middleware\AiMiddlewarePipeline;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\ProviderResolver;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Psr\Container\ContainerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the release notes promise for the window between deploying the code and
 * running the database schema update: a request is still answered, without its
 * tone of voice. Two separate faults are in play there, the assignment table's
 * new `hidden` column and the fragment cache pool's table, and each one used to
 * be an uncaught exception on every single request.
 *
 * Only a real DBMS can enforce this: SQLite answers a query against a column
 * that does not exist with the column name as a string literal instead of
 * failing, so run it with `runTests.sh -s functional -d mariadb`.
 */
final class SchemaNotUpdatedYetTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aim_page_prompt_fragment';

    protected array $testExtensionsToLoad = [
        'b13/aim',
    ];

    private PromptRecordingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new PromptRecordingProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->provider);
        $this->get(AiProviderRegistry::class)->addProvider(new AiProviderManifest(
            identifier: 'testprovider',
            name: 'Test Provider',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [TextGenerationCapableInterface::class],
            serviceName: 'test.provider',
            container: $container,
        ));
    }

    #[Test]
    public function aRequestIsStillAnsweredWithoutItsToneOfVoice(): void
    {
        // SQLite refuses to drop a column an index still names, so the state
        // this test is about cannot even be produced there. It also could not
        // be observed: SQLite answers a query against a missing column with
        // the column name as a string literal instead of failing.
        if ($this->getConnectionPool()->getConnectionForTable(self::TABLE)->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Needs a DBMS that rejects a query against a column that does not exist.');
        }

        $this->seedConfiguration();
        $pageId = $this->seedPageWithFragment('Always answer in a formal tone.');

        // Exactly the state after deploying and before "Analyze Database".
        $this->dropColumn('tx_aim_page_prompt_fragment', 'hidden');
        $this->dropTable('cache_aim_prompt_fragments');

        $response = $this->dispatch($pageId);

        self::assertTrue($response->isSuccessful(), 'The request has to be answered, not fail.');
        self::assertSame('answered', $response->content);
        self::assertStringNotContainsString(
            'Always answer in a formal tone.',
            $this->provider->systemPrompt,
            'The tone cannot be composed in this state; it has to be left out rather than guessed at.',
        );
    }

    private function dispatch(int $pageId): TextResponse
    {
        $chain = $this->get(ProviderResolver::class)->buildFallbackChain(TextGenerationCapableInterface::class);

        return $this->get(AiMiddlewarePipeline::class)->dispatchWithFallback(
            new TextGenerationRequest(
                configuration: $chain->getPrimary()->configuration,
                prompt: 'Anything at all, long enough not to be reclassified as trivial by the router.',
                pageId: $pageId,
            ),
            $chain,
        );
    }

    private function seedConfiguration(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration')->insert('tx_aim_configuration', [
            'pid' => 0,
            'ai_provider' => 'testprovider',
            'title' => 'primary',
            'model' => 'a-model',
            'default' => 1,
            'rerouting_allowed' => 1,
            'accepts_rerouted_requests' => 1,
            'be_groups' => '',
        ]);
    }

    private function seedPageWithFragment(string $prompt): int
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Tone page']);

        $fragments = $this->getConnectionPool()->getConnectionForTable('tx_aim_prompt_fragment');
        $fragments->insert('tx_aim_prompt_fragment', ['pid' => 0, 'title' => 'Tone', 'prompt' => $prompt, 'scope' => 'all']);
        $fragmentUid = (int)$fragments->lastInsertId();

        $this->getConnectionPool()->getConnectionForTable('tx_aim_page_prompt_fragment')
            ->insert('tx_aim_page_prompt_fragment', ['pid' => 1, 'parent_page' => 1, 'fragment' => $fragmentUid]);

        return 1;
    }

    private function dropColumn(string $table, string $column): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable($table);
        $connection->executeStatement(sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $connection->quoteIdentifier($table),
            $connection->quoteIdentifier($column),
        ));
    }

    private function dropTable(string $table): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_aim_configuration');
        $connection->executeStatement('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier($table));
    }
}

final class PromptRecordingProvider implements AiProviderInterface, TextGenerationCapableInterface
{
    public string $systemPrompt = '';

    public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
    {
        $this->systemPrompt = $request->systemPrompt;

        return new TextResponse('answered');
    }
}
