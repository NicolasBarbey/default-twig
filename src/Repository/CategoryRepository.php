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
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;

final readonly class CategoryRepository
{
    /**
     * @return ObjectCollection<int, Category>
     */
    public function findByDefaultTemplateId(int $templateId): ObjectCollection
    {
        /** @var ObjectCollection<int, Category> $result */
        $result = CategoryQuery::create()
            ->filterByDefaultTemplateId($templateId)
            ->orderByPosition()
            ->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, Category>
     */
    public function findChildrenOrderedByPosition(int $parentId, string $locale): ObjectCollection
    {
        /** @var ObjectCollection<int, Category> $categories */
        $categories = CategoryQuery::create()
            ->filterByParent($parentId)
            ->joinWithI18n($locale)
            ->orderByPosition()
            ->find();

        foreach ($categories as $category) {
            $category->setLocale($locale);
        }

        return $categories;
    }

    /**
     * Depth-first flattened tree (position order) for indented <select> options.
     *
     * @return list<array{id: int, title: string, depth: int}>
     */
    public function flatTree(string $locale): array
    {
        $out = [];
        $this->appendTree($this->childrenByParent($locale), 0, 0, $out);

        return $out;
    }

    /**
     * Every category with its translation, grouped by parent id in position order:
     * one query for a whole tree instead of one per node.
     *
     * @return array<int, list<Category>>
     */
    public function childrenByParent(string $locale): array
    {
        $categories = CategoryQuery::create()
            ->joinWithI18n($locale)
            ->orderByPosition()
            ->find();

        $children = [];
        foreach ($categories as $category) {
            \assert($category instanceof Category);
            $category->setLocale($locale);
            $children[(int) $category->getParent()][] = $category;
        }

        return $children;
    }

    /**
     * @param array<int, list<Category>>                     $children
     * @param list<array{id: int, title: string, depth: int}> $out
     */
    private function appendTree(array $children, int $parentId, int $depth, array &$out): void
    {
        foreach ($children[$parentId] ?? [] as $category) {
            $out[] = [
                'id' => (int) $category->getId(),
                'title' => (string) $category->getTitle(),
                'depth' => $depth,
            ];
            $this->appendTree($children, (int) $category->getId(), $depth + 1, $out);
        }
    }

    /**
     * A category id plus every descendant id — the set that must NOT be selectable
     * as the category's own parent (prevents a parent cycle). Self-guarded against loops.
     *
     * @return list<int>
     */
    public function subtreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $this->collectDescendants($rootId, $ids);

        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    private function collectDescendants(int $parentId, array &$ids): void
    {
        foreach (CategoryQuery::create()->filterByParent($parentId)->select('Id')->find() as $childId) {
            $childId = (int) $childId;
            if (!\in_array($childId, $ids, true)) {
                $ids[] = $childId;
                $this->collectDescendants($childId, $ids);
            }
        }
    }

    public function find(int $id, string $locale): ?Category
    {
        $category = CategoryQuery::create()->findPk($id);
        if ($category === null) {
            return null;
        }
        $category->setLocale($locale);

        return $category;
    }

    public function countChildren(int $parentId): int
    {
        return CategoryQuery::create()->filterByParent($parentId)->count();
    }

    /** @return array{previous: ?int, next: ?int} */
    public function findPreviousNext(Category $current): array
    {
        $previous = CategoryQuery::create()
            ->filterByParent($current->getParent())
            ->filterByPosition($current->getPosition(), Criteria::LESS_THAN)
            ->orderByPosition(Criteria::DESC)
            ->findOne();
        $next = CategoryQuery::create()
            ->filterByParent($current->getParent())
            ->filterByPosition($current->getPosition(), Criteria::GREATER_THAN)
            ->orderByPosition(Criteria::ASC)
            ->findOne();

        return [
            'previous' => $previous !== null ? (int) $previous->getId() : null,
            'next' => $next !== null ? (int) $next->getId() : null,
        ];
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function buildBreadcrumbPath(?Category $current, string $locale): array
    {
        if ($current === null) {
            return [];
        }

        $path = [];
        $node = $current;
        while ($node !== null && (int) $node->getId() !== 0) {
            $node->setLocale($locale);
            array_unshift($path, [
                'id' => (int) $node->getId(),
                'title' => (string) $node->getTitle(),
            ]);
            $parentId = (int) $node->getParent();
            $node = $parentId > 0 ? CategoryQuery::create()->findPk($parentId) : null;
        }

        return $path;
    }
}
