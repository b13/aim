<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Domain\Repository;

use B13\Aim\Crypto\ApiKeyEncryption;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Model\ProviderConfigurationFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ProviderConfigurationRepository
{
    private const TABLE = 'tx_aim_configuration';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ApiKeyEncryption $encryption,
    ) {}

    public function findByUid(int $uid): ?ProviderConfiguration
    {
        $qb = $this->getQueryBuilder(false, false);
        return $this->map($qb
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAllAssociative())[0] ?? null;
    }

    public function findDefault(): ?ProviderConfiguration
    {
        $qb = $this->getQueryBuilder(false, false);
        return $this->map($qb
            ->where($qb->expr()->eq('default', $qb->createNamedParameter(1, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAllAssociative())[0] ?? null;
    }

    /**
     * @return ProviderConfiguration[]
     */
    public function findAll(): array
    {
        return $this->map($this->getQueryBuilder(true, false)
            ->executeQuery()
            ->fetchAllAssociative());
    }

    /**
     * @return ProviderConfiguration[]
     */
    public function findAllEnabled(): array
    {
        $qb = $this->getQueryBuilder(true, false);
        $qb->andWhere($qb->expr()->eq('disabled', $qb->createNamedParameter(0, Connection::PARAM_INT)));
        return $this->map($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * @return ProviderConfiguration[]
     */
    public function findByDemand(ProviderConfigurationDemand $demand): array
    {
        return $this->map($this->getQueryBuilderForDemand($demand)
            ->setMaxResults($demand->getLimit())
            ->setFirstResult($demand->getOffset())
            ->executeQuery()
            ->fetchAllAssociative());
    }

    /**
     * @return ProviderConfiguration[]
     */
    public function findByProviderIdentifier(string $identifier): array
    {
        $qb = $this->getQueryBuilder(false, false);
        return $this->map($qb
            ->where($qb->expr()->eq('ai_provider', $qb->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function countByDemand(ProviderConfigurationDemand $demand): int
    {
        return (int)$this->getQueryBuilderForDemand($demand)
            ->count('*')
            ->executeQuery()
            ->fetchOne();
    }

    public function findAllRawSorted(bool $enabledOnly = false): array
    {
        $qb = $this->getQueryBuilder();
        if ($enabledOnly) {
            $qb->andWhere($qb->expr()->eq('disabled', $qb->createNamedParameter(0, Connection::PARAM_INT)));
        }
        $qb->orderBy('default', 'DESC');
        $qb->addOrderBy('uid', 'DESC');
        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function countAll(): int
    {
        return (int)$this->getQueryBuilder(false, false)
            ->count('*')
            ->executeQuery()
            ->fetchOne();
    }

    public function countAllRaw(): int
    {
        return (int)$this->getQueryBuilder()
            ->count('*')
            ->executeQuery()
            ->fetchOne();
    }

    public function updateTotalCost(int $uid, float $cost): bool
    {
        if ($uid <= 0 || $cost <= 0.0) {
            return false;
        }
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affectedRows = $connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET total_cost = total_cost + :cost WHERE uid = :uid',
            ['cost' => $cost, 'uid' => $uid],
            [Connection::PARAM_STR, Connection::PARAM_INT]
        );
        return $affectedRows > 0;
    }

    protected function getQueryBuilderForDemand(ProviderConfigurationDemand $demand): QueryBuilder
    {
        $qb = $this->getQueryBuilder(false, false);
        $qb->orderBy(
            $demand->getOrderField(),
            $demand->getOrderDirection()
        );
        // Ensure deterministic ordering.
        if ($demand->getOrderField() !== 'uid') {
            $qb->addOrderBy('uid', 'asc');
        }

        $constraints = [];
        if ($demand->hasTitle()) {
            $escapedLikeString = '%' . $qb->escapeLikeWildcards($demand->getTitle()) . '%';
            $constraints[] = $qb->expr()->like(
                'title',
                $qb->createNamedParameter($escapedLikeString)
            );
        }
        if ($demand->hasAiProvider()) {
            $constraints[] = $qb->expr()->eq(
                'ai_provider',
                $qb->createNamedParameter($demand->getAiProvider())
            );
        }

        if (!empty($constraints)) {
            $qb->where(...$constraints);
        }
        return $qb;
    }

    /**
     * @return ProviderConfiguration[]
     */
    protected function map(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapSingleRow($row);
        }
        return $items;
    }

    protected function mapSingleRow(array $row): ProviderConfiguration
    {
        if (isset($row['api_key']) && $row['api_key'] !== '') {
            $row['api_key'] = $this->encryption->decrypt((string)$row['api_key']);
        }
        return ProviderConfigurationFactory::fromRow($row);
    }

    protected function getQueryBuilder(bool $addDefaultOrderByClause = true, bool $useDefaultRestrictions = true): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        if (!$useDefaultRestrictions) {
            $qb->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        }
        $qb->select('*')->from(self::TABLE);
        if ($addDefaultOrderByClause) {
            $qb->orderBy('title', 'ASC')
                ->addOrderBy('uid', 'ASC');
        }
        return $qb;
    }
}
