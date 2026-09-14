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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\Accessory;
use Thelia\Model\AccessoryQuery;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\Folder;
use Thelia\Model\FolderQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductAssociatedContentQuery;
use Thelia\Model\ProductAssociationType;
use Thelia\Model\ProductAssociationTypeQuery;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductQuery;
use Thelia\Tools\TokenProvider;

final readonly class ProductRelationsContext
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TokenProvider $tokens,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product, string $locale, string $uiLocale): array
    {
        $defaultCategoryId = (int) $product->getDefaultCategoryId();
        $additionalCategories = $this->additionalCategories($product, $locale, $defaultCategoryId);
        $additionalCategoryIds = array_map(static fn (array $row): int => $row['id'], $additionalCategories);
        $excludedFromTree = array_merge([$defaultCategoryId], $additionalCategoryIds);

        return [
            'product' => $product,
            'default_category_id' => $defaultCategoryId,
            'folder_tree' => $this->folderTree($locale),
            'category_tree_for_relations' => $this->categoryTree($locale),
            'category_tree_for_additional' => $this->categoryTree($locale, excluded: $excludedFromTree),
            'assigned_contents' => $this->assignedContents($product, $locale),
            'relation_blocks' => $this->relationBlocks($product, $locale, $uiLocale),
            'additional_categories' => $additionalCategories,
            'available_related_content_url' => $this->urls->generate('admin.product.available-related-content', ['productId' => (int) $product->getId(), 'folderId' => 0, '_format' => 'json']),
            'update_content_position_url' => $this->urls->generate('admin.product.update-content-position'),
            'token' => $this->tokens->assignToken(),
        ];
    }

    /**
     * @return list<array{id: int, title: string, level: int}>
     */
    private function folderTree(string $locale, int $parentId = 0, int $level = 0): array
    {
        $items = [];
        $folders = FolderQuery::create()
            ->filterByParent($parentId)
            ->orderByPosition()
            ->find();

        foreach ($folders as $folder) {
            \assert($folder instanceof Folder);
            $folder->setLocale($locale);
            $items[] = ['id' => (int) $folder->getId(), 'title' => (string) $folder->getTitle(), 'level' => $level];
            foreach ($this->folderTree($locale, (int) $folder->getId(), $level + 1) as $child) {
                $items[] = $child;
            }
        }

        return $items;
    }

    /**
     * @param list<int> $excluded
     *
     * @return list<array{id: int, title: string, level: int, disabled: bool}>
     */
    private function categoryTree(string $locale, int $parentId = 0, int $level = 0, array $excluded = []): array
    {
        $items = [];
        $categories = CategoryQuery::create()
            ->filterByParent($parentId)
            ->orderByPosition()
            ->find();

        foreach ($categories as $category) {
            \assert($category instanceof Category);
            $category->setLocale($locale);
            $id = (int) $category->getId();
            $items[] = ['id' => $id, 'title' => (string) $category->getTitle(), 'level' => $level, 'disabled' => \in_array($id, $excluded, true)];
            foreach ($this->categoryTree($locale, $id, $level + 1, $excluded) as $child) {
                $items[] = $child;
            }
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string, position: int, url: string}>
     */
    private function assignedContents(Product $product, string $locale): array
    {
        $assignments = ProductAssociatedContentQuery::create()
            ->filterByProductId((int) $product->getId())
            ->orderByPosition()
            ->find();

        $items = [];
        foreach ($assignments as $assignment) {
            $content = ContentQuery::create()->findPk((int) $assignment->getContentId());
            if ($content === null) {
                continue;
            }
            $content->setLocale($locale);
            $items[] = [
                'id' => (int) $content->getId(),
                'title' => (string) $content->getTitle(),
                'position' => (int) $assignment->getPosition(),
                'url' => $this->urls->generate('admin.content.update', ['content_id' => (int) $content->getId()]),
            ];
        }

        return $items;
    }

    /**
     * One block per visible type, in the order the merchant gave the types.
     *
     * Three queries whatever the number of blocks: the types, every relation of the
     * product whatever its type, and the associated products in one go. Reading each
     * block on its own would multiply both the relation query and the N+1 the previous
     * accessory-only read already had.
     *
     * @return list<array{type_code: string, title: string, description: string|null, reciprocal: bool, id_prefix: string, add_url: string, delete_url: string, available_url: string, position_url: string, header_hook: string, row_hook: string, rows: list<array{id: int, associated_product_id: int, title: string, position: int}>}>
     */
    private function relationBlocks(Product $product, string $locale, string $uiLocale): array
    {
        $types = ProductAssociationTypeQuery::create()
            ->filterByVisible(1)
            ->orderByPosition()
            ->find();

        if (0 === $types->count()) {
            return [];
        }

        $relations = AccessoryQuery::create()
            ->filterByProductId((int) $product->getId())
            ->orderByPosition()
            ->find();

        $associatedTitles = $this->associatedProductTitles($relations, $locale);

        $rowsByType = [];
        foreach ($relations as $relation) {
            \assert($relation instanceof Accessory);
            $associatedProductId = (int) $relation->getAccessory();

            if (!isset($associatedTitles[$associatedProductId])) {
                continue;
            }

            $rowsByType[(int) $relation->getTypeId()][] = [
                'id' => (int) $relation->getId(),
                'associated_product_id' => $associatedProductId,
                'title' => $associatedTitles[$associatedProductId],
                'position' => (int) $relation->getPosition(),
            ];
        }

        $productId = (int) $product->getId();
        $blocks = [];

        foreach ($types as $type) {
            \assert($type instanceof ProductAssociationType);
            $type->setLocale($uiLocale);
            $code = (string) $type->getCode();
            $isAccessory = ProductAssociationType::CODE_ACCESSORY === $code;

            $blocks[] = [
                'type_code' => $code,
                'title' => (string) $type->getTitle(),
                'description' => $type->getDescription(),
                'reciprocal' => $type->isReciprocal(),
                'id_prefix' => 'product-relation-'.str_replace('_', '-', $code),
                'add_url' => $this->urls->generate('admin.products.associations.add'),
                'delete_url' => $this->urls->generate('admin.products.associations.delete'),
                'available_url' => $this->urls->generate('admin.product.associations-content', [
                    'productId' => $productId,
                    'typeCode' => $code,
                    'categoryId' => 0,
                    '_format' => 'json',
                ]),
                'position_url' => $this->urls->generate('admin.product.update-association-position'),
                // Modules hooked on the accessory table keep the names they were written
                // against; the types this release adds get their own.
                'header_hook' => $isAccessory ? 'product.accessories-table-header' : 'product.associations-table-header',
                'row_hook' => $isAccessory ? 'product.accessories-table-row' : 'product.associations-table-row',
                'rows' => $rowsByType[(int) $type->getId()] ?? [],
            ];
        }

        return $blocks;
    }

    /**
     * @param iterable<mixed> $relations
     *
     * @return array<int, string>
     */
    private function associatedProductTitles(iterable $relations, string $locale): array
    {
        $ids = [];
        foreach ($relations as $relation) {
            \assert($relation instanceof Accessory);
            $ids[] = (int) $relation->getAccessory();
        }

        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            return [];
        }

        $titles = [];
        $products = ProductQuery::create()
            ->filterById($ids, Criteria::IN)
            ->find();

        foreach ($products as $associated) {
            \assert($associated instanceof Product);
            $associated->setLocale($locale);
            $titles[(int) $associated->getId()] = (string) $associated->getTitle();
        }

        return $titles;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function additionalCategories(Product $product, string $locale, int $defaultCategoryId): array
    {
        $assignments = ProductCategoryQuery::create()
            ->filterByProductId((int) $product->getId())
            ->filterByDefaultCategory(false)
            ->find();

        $items = [];
        foreach ($assignments as $assignment) {
            $categoryId = (int) $assignment->getCategoryId();
            if ($categoryId === $defaultCategoryId) {
                continue;
            }
            $category = CategoryQuery::create()->findPk($categoryId);
            if ($category === null) {
                continue;
            }
            $category->setLocale($locale);
            $items[] = ['id' => $categoryId, 'title' => (string) $category->getTitle()];
        }

        return $items;
    }
}
