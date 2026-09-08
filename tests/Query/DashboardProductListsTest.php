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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The low-stock panel of the dashboard. Each of its rows named its product, and
 * naming it took two queries: one to fetch the product behind the combination,
 * one to fetch its title.
 */
final class DashboardProductListsTest extends IntegrationTestCase
{
    private const THRESHOLD = 1000;

    private const LIMIT = 5;

    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = new ProductRepository();
    }

    public function testTheLowStockPanelReadsItsProductsAndTheirTitlesWithTheCombinations(): void
    {
        $this->givenCombinationsUnderTheThreshold();

        $titles = [];

        $queries = QueryCounter::count(function () use (&$titles): void {
            foreach ($this->products->findLowStock(self::THRESHOLD, self::LIMIT, 'en_US') as $saleElement) {
                $titles[] = (string) $saleElement->getProduct()->getTitle();
            }
        });

        self::assertNotSame([], $titles);
        self::assertSame([], array_filter($titles, static fn (string $title): bool => $title === ''));
        self::assertSame(1, $queries, 'The combinations, their products and the titles are one joined query.');
    }

    /**
     * Combinations tied on a quantity used to come back in whatever order the
     * database's sort happened to produce, so the panel showed a different set on
     * every refresh. The order is now total: quantity first, then id.
     */
    public function testTheLowStockPanelOrdersTiedCombinationsByIdRatherThanLeavingItToTheDatabase(): void
    {
        $this->givenCombinationsUnderTheThreshold();

        $expected = array_map(
            static fn (ProductSaleElements $saleElement): int => (int) $saleElement->getId(),
            iterator_to_array(
                ProductSaleElementsQuery::create()
                    ->filterByQuantity(self::THRESHOLD, Criteria::LESS_EQUAL)
                    ->orderByQuantity(Criteria::ASC)
                    ->orderById(Criteria::ASC)
                    ->limit(self::LIMIT)
                    ->find(),
            ),
        );

        self::assertCount(self::LIMIT, $expected);
        self::assertSame(
            array_values($expected),
            $this->idsOf($this->products->findLowStock(self::THRESHOLD, self::LIMIT, 'en_US')),
        );
    }

    public function testTheLowStockPanelStillReadsTheLeastStockedCombinationsFirst(): void
    {
        $this->givenCombinationsUnderTheThreshold();

        $quantities = array_map(
            static fn (ProductSaleElements $saleElement): int => (int) $saleElement->getQuantity(),
            $this->products->findLowStock(self::THRESHOLD, self::LIMIT, 'en_US'),
        );

        $sorted = $quantities;
        sort($sorted);

        self::assertSame($sorted, $quantities);
    }

    private function givenCombinationsUnderTheThreshold(): void
    {
        $factory = $this->createFixtureFactory();
        $category = $factory->category();
        $taxRule = $factory->taxRule();
        $currency = $factory->currency();

        // Two combinations share a quantity on purpose: that is the tie the panel
        // used to resolve differently on every read.
        foreach ([3, 7, 7, 11, 42, 900] as $index => $quantity) {
            $factory->product($category, $taxRule, $currency, [
                'ref' => 'LOW-STOCK-'.$index,
                'title' => 'Low stock '.$index,
                'baseQuantity' => $quantity,
            ]);
        }
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<int>
     */
    private function idsOf(array $saleElements): array
    {
        return array_map(static fn (ProductSaleElements $saleElement): int => (int) $saleElement->getId(), $saleElements);
    }
}
