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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\AttributeTemplateQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The order of the combinations tab: by position, then by the positions of their attributes and values,
 * then by id. Ties on position (all of them after a Thelia 2 migration) follow the attribute order, as Thelia 2 did.
 */
final readonly class CombinationDisplayOrder
{
    /**
     * @return list<ProductSaleElements>
     */
    public function of(Product $product, ?ConnectionInterface $connection = null): array
    {
        $attributePositions = $this->templateAttributePositions($product, $connection);

        $combinations = [];
        foreach (ProductSaleElementsQuery::create()->filterByProductId((int) $product->getId())->orderByPosition()->orderById()->find($connection) as $combination) {
            \assert($combination instanceof ProductSaleElements);
            $combinations[] = $combination;
        }
        $attributeOrders = $this->attributeOrders(array_map(static fn (ProductSaleElements $combination): int => (int) $combination->getId(), $combinations), $attributePositions, $connection);

        $entries = [];
        foreach ($combinations as $combination) {
            $entries[] = [
                'combination' => $combination,
                'key' => [(int) $combination->getPosition(), $attributeOrders[(int) $combination->getId()] ?? [], (int) $combination->getId()],
            ];
        }

        usort($entries, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);

        return array_column($entries, 'combination');
    }

    /**
     * Sorted, so that a combination whose values are linked in another order reads the same. One
     * query for all: a move reads them while it holds the lock on the product's combinations.
     *
     * @param list<int>       $combinationIds
     * @param array<int, int> $attributePositions
     *
     * @return array<int, list<array{int, int}>>
     */
    private function attributeOrders(array $combinationIds, array $attributePositions, ?ConnectionInterface $connection): array
    {
        if ($combinationIds === []) {
            return [];
        }

        $orders = [];
        $links = AttributeCombinationQuery::create()
            ->filterByProductSaleElementsId($combinationIds, Criteria::IN)
            ->joinWithAttribute()
            ->joinWithAttributeAv()
            ->find($connection);
        foreach ($links as $link) {
            $attribute = $link->getAttribute();
            $attributeAv = $link->getAttributeAv();
            if ($attribute === null || $attributeAv === null) {
                continue;
            }
            $orders[(int) $link->getProductSaleElementsId()][] = [
                $attributePositions[(int) $attribute->getId()] ?? (int) $attribute->getPosition(),
                (int) $attributeAv->getPosition(),
            ];
        }
        foreach ($orders as $id => $order) {
            sort($order);
            $orders[$id] = $order;
        }

        return $orders;
    }

    /**
     * @return array<int, int>
     */
    private function templateAttributePositions(Product $product, ?ConnectionInterface $connection): array
    {
        $templateId = (int) ($product->getTemplateId() ?? 0);
        if ($templateId <= 0) {
            return [];
        }

        $positions = [];
        foreach (AttributeTemplateQuery::create()->filterByTemplateId($templateId)->find($connection) as $entry) {
            $positions[(int) $entry->getAttributeId()] = (int) $entry->getPosition();
        }

        return $positions;
    }
}
