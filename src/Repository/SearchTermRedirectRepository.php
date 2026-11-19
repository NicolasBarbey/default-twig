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
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Thelia\Domain\Search\Dictionary\SearchRedirectTargetType;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\SearchTermRedirect;
use Thelia\Model\SearchTermRedirectQuery;

final readonly class SearchTermRedirectRepository
{
    public const PAGE_SIZE = 25;

    public function find(int $id): ?SearchTermRedirect
    {
        return $id > 0 ? SearchTermRedirectQuery::create()->findPk($id) : null;
    }

    /**
     * @return array{items: list<SearchTermRedirect>, page: int, lastPage: int}
     */
    public function page(int $languageId, string $direction, int $page): array
    {
        $query = SearchTermRedirectQuery::create()->filterByLangId($languageId);
        $lastPage = max(1, (int) ceil($query->count() / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $query
            ->orderByTerm('desc' === strtolower($direction) ? Criteria::DESC : Criteria::ASC)
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE);

        $items = [];
        foreach ($query->find() as $redirect) {
            \assert($redirect instanceof SearchTermRedirect);
            $items[] = $redirect;
        }

        return ['items' => $items, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function titlesOf(string $type, array $ids, string $locale): array
    {
        $query = match ($type) {
            SearchRedirectTargetType::PRODUCT => ProductQuery::create(),
            SearchRedirectTargetType::CATEGORY => CategoryQuery::create(),
            SearchRedirectTargetType::CONTENT => ContentQuery::create(),
            SearchRedirectTargetType::FOLDER => FolderQuery::create(),
            SearchRedirectTargetType::BRAND => BrandQuery::create(),
            default => null,
        };

        if (!$query instanceof ModelCriteria || [] === $ids) {
            return [];
        }

        $titles = [];
        foreach ($query->filterById($ids, Criteria::IN)->joinWithI18n($locale)->find() as $entity) {
            $title = trim((string) $entity->setLocale($locale)->getTitle());
            if ('' !== $title) {
                $titles[(int) $entity->getId()] = $title;
            }
        }

        return $titles;
    }
}
