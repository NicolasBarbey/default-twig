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

use Propel\Runtime\Propel;
use Thelia\Model\AttributeQuery;
use Thelia\Model\BrandQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\TemplateQuery;

/**
 * Locale-aware option lists and slider bounds for the catalog filter form.
 * Everything is memoised per locale for the duration of the request.
 */
final class ProductFilterCatalog
{
    /** @var array<string, list<array{id: int, title: string}>> */
    private array $brandsByLocale = [];

    /** @var array<string, list<array{id: int, title: string}>> */
    private array $templatesByLocale = [];

    /** @var array<string, list<array{id: int, title: string}>> */
    private array $featuresByLocale = [];

    /** @var array<string, list<array{id: int, title: string}>> */
    private array $attributesByLocale = [];

    /** @var array{min: float, max: float, step: float}|null */
    private ?array $priceBounds = null;

    /** @var array{min: float, max: float, step: float}|null */
    private ?array $quantityBounds = null;

    /**
     * @return list<array{id: int, title: string}>
     */
    public function brands(string $locale): array
    {
        return $this->brandsByLocale[$locale] ??= $this->localizedList(
            BrandQuery::create()->orderByPosition(),
            $locale,
        );
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function templates(string $locale): array
    {
        return $this->templatesByLocale[$locale] ??= $this->localizedList(
            TemplateQuery::create(),
            $locale,
        );
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function features(string $locale): array
    {
        return $this->featuresByLocale[$locale] ??= $this->localizedList(
            FeatureQuery::create()->orderByPosition(),
            $locale,
        );
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function attributes(string $locale): array
    {
        return $this->attributesByLocale[$locale] ??= $this->localizedList(
            AttributeQuery::create()->orderByPosition(),
            $locale,
        );
    }

    /**
     * @return array{min: float, max: float, step: float}
     */
    public function priceBounds(): array
    {
        return $this->priceBounds ??= $this->bounds(
            'SELECT COALESCE(MIN(pp.price), 0) AS min_value, COALESCE(MAX(pp.price), 0) AS max_value
             FROM product_price pp
             INNER JOIN product_sale_elements pse ON pse.id = pp.product_sale_elements_id AND pse.is_default = 1',
            step: 1.0,
        );
    }

    /**
     * @return array{min: float, max: float, step: float}
     */
    public function quantityBounds(): array
    {
        return $this->quantityBounds ??= $this->bounds(
            'SELECT 0 AS min_value, COALESCE(MAX(total), 0) AS max_value FROM (
                SELECT SUM(quantity) AS total FROM product_sale_elements GROUP BY product_id
             ) totals',
            step: 1.0,
        );
    }

    /**
     * @return array{min: float, max: float, step: float}
     */
    private function bounds(string $sql, float $step): array
    {
        $statement = Propel::getConnection(ProductTableMap::DATABASE_NAME)->query($sql);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: ['min_value' => 0, 'max_value' => 0];

        $min = (float) $row['min_value'];
        $max = max($min, (float) $row['max_value']);

        return [
            'min' => floor($min),
            // Round the upper bound up so the slider can always reach the priciest item.
            'max' => ceil($max),
            'step' => $step,
        ];
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\ModelCriteria $query
     *
     * @return list<array{id: int, title: string}>
     */
    private function localizedList($query, string $locale): array
    {
        $items = [];
        foreach ($query->joinWithI18n($locale)->find() as $model) {
            $model->setLocale($locale);
            // Templates carry a localized `name`, catalog entities a `title`.
            $label = trim((string) (method_exists($model, 'getTitle') ? $model->getTitle() : $model->getName()));
            $items[] = [
                'id' => (int) $model->getId(),
                'title' => $label !== '' ? $label : '#'.$model->getId(),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $items;
    }
}
