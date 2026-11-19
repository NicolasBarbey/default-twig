<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\SearchSynonym;
use Thelia\Model\SearchSynonymQuery;

final readonly class SearchSynonymRepository
{
    public const PAGE_SIZE = 25;

    public function find(int $id): ?SearchSynonym
    {
        return $id > 0 ? SearchSynonymQuery::create()->findPk($id) : null;
    }

    /**
     * @return array{items: list<SearchSynonym>, page: int, lastPage: int}
     */
    public function page(int $languageId, string $direction, int $page): array
    {
        $query = SearchSynonymQuery::create()->filterByLangId($languageId);
        $lastPage = max(1, (int) ceil($query->count() / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $query
            ->orderByTerm('desc' === strtolower($direction) ? Criteria::DESC : Criteria::ASC)
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE);

        $items = [];
        foreach ($query->find() as $synonym) {
            \assert($synonym instanceof SearchSynonym);
            $items[] = $synonym;
        }

        return ['items' => $items, 'page' => $page, 'lastPage' => $lastPage];
    }
}
