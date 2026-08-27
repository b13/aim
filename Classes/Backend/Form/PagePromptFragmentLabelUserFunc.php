<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Backend\Form;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * tx_aim_page_prompt_fragment's `fragment` column is just an integer FK.
 * "ctrl.label_userFunc" resolves it to the referenced library fragment's
 * own title, so the page's IRRE grid shows something an editor recognizes
 * instead of a raw uid.
 */
final class PagePromptFragmentLabelUserFunc
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getLabel(array &$params): void
    {
        $title = $this->resolveTitle($params['row']['fragment'] ?? null);
        $params['title'] = $title !== '' ? $title : $this->getLanguageService()->sL(
            'LLL:EXT:aim/Resources/Private/Language/locallang_tca.xlf:tx_aim_page_prompt_fragment.noFragmentSelected'
        );
    }

    private function resolveTitle(mixed $rawValue): string
    {
        if (is_array($rawValue)) {
            $firstItem = $rawValue[0] ?? null;
            if (is_array($firstItem) && isset($firstItem['title'])) {
                return (string)$firstItem['title'];
            }
            $rawValue = $firstItem;
        }
        $fragmentUid = $rawValue !== null && $rawValue !== '' ? (int)$rawValue : 0;
        return $fragmentUid > 0 ? $this->resolveFragmentTitle($fragmentUid) : '';
    }

    private function resolveFragmentTitle(int $fragmentUid): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_aim_prompt_fragment');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $title = $qb->select('title')
            ->from('tx_aim_prompt_fragment')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($fragmentUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        return is_string($title) ? $title : '';
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
