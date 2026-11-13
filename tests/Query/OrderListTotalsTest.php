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

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\Order\OrderFilters;
use BackOfficeDefaultTwigBundle\Service\Order\OrderRoundingRule;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The amount and the line count the order list shows on every row.
 *
 * Both were read one order at a time, so a page of 25 orders spent 50 queries on
 * top of the page itself. They are now read for the whole page at once — and the
 * amount has to stay the amount, to the cent: it is the figure the shop reconciles
 * its invoices against, so every case below is checked against
 * Order::getTotalAmount() rather than against a number written in this file.
 */
final class OrderListTotalsTest extends IntegrationTestCase
{
    /** Mirrors the pivot Thelia's 2.4 upgrade writes once. */
    private const LEGACY_ROUNDING_PIVOT = 'last_legacy_rounding_order_id';

    /** Mirrors the pivot Thelia's 3.4 upgrade writes once. */
    private const FLOAT_LINE_ROUNDING_PIVOT = 'last_float_line_rounding_order_id';

    /** Mirrors the pivot Thelia's 3.3 upgrade writes once. */
    private const TEXT_PROMO_PRICE_PIVOT = 'last_text_promo_price_order_id';

    private const ROUNDING_MODE = 'order_rounding_mode';

    private OrderRepository $orders;

    private bool $legacyPivotWritten = false;

    /** @var array<string, ?string> the configuration values a test changed, as they were before */
    private array $configBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new OrderRepository(new OrderAccess(new AdminSaleUnitScope()));
    }

    protected function tearDown(): void
    {
        // ConfigQuery keeps a static cache, which the transaction rollback does not
        // reach: a pivot written here would follow the process into the next test.
        if ($this->legacyPivotWritten) {
            ConfigQuery::write(self::LEGACY_ROUNDING_PIVOT, '0');
            $this->legacyPivotWritten = false;
        }

        foreach ($this->configBefore as $name => $value) {
            if (null === $value) {
                ConfigQuery::create()->filterByName($name)->delete();
                ConfigQuery::resetCache();

                continue;
            }

            ConfigQuery::write($name, $value);
        }
        $this->configBefore = [];

        parent::tearDown();
    }

    /**
     * Amounts that exercise what the two formulas disagree on: a unit price with
     * more decimals than a cent, a quantity that multiplies the rounding error, a
     * tax that lands on a half cent, a discount larger than the lines it applies
     * to, and postage added after the clamp.
     */
    public static function orderShapes(): \Generator
    {
        yield 'a plain order' => [[['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 2]], '0', '0'];

        yield 'a price stored below the cent' => [[['price' => '0.004500', 'tax' => '0.000900', 'quantity' => 1000]], '0', '0'];

        yield 'a tax landing on a half cent' => [[['price' => '1.000000', 'tax' => '0.005000', 'quantity' => 3]], '0', '0'];

        yield 'several lines' => [[
            ['price' => '12.345600', 'tax' => '2.469100', 'quantity' => 3],
            ['price' => '0.999900', 'tax' => '0.199980', 'quantity' => 7],
            ['price' => '99.990000', 'tax' => '19.998000', 'quantity' => 1],
        ], '0', '0'];

        yield 'a discount' => [[['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 5]], '13.370000', '0'];

        yield 'a discount larger than the order' => [[['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 1]], '500.000000', '7.500000'];

        yield 'postage on top' => [[['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 4]], '0', '9.900000'];

        yield 'an order with no line' => [[], '0', '5.000000'];

        yield 'a promo line' => [[['price' => '20.000000', 'tax' => '4.000000', 'quantity' => 2, 'promo' => true, 'promoPrice' => '13.333300', 'promoTax' => '2.666660']], '0', '0'];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('orderShapes')]
    public function testTheAmountIsTheAmountTheOrderModelAnswers(array $lines, string $discount, string $postage): void
    {
        $order = $this->orderWith($lines, $discount, $postage);

        $batched = $this->orders->findTotalAmountByOrder([(int) $order->getId()]);

        self::assertSame(
            number_format($order->getTotalAmount(), 2, '.', ''),
            number_format($batched[(int) $order->getId()], 2, '.', ''),
            'The batched amount and the model amount have to show the same cents.',
        );
    }

    /**
     * A line sold by measure lands on a half cent: 1.25 kg at 14.82 is 18.525, charged
     * 18.53. The list shows the amount the order model answers, to the float it is
     * formatted from, not a sum the money formatter then rounds to the even cent.
     */
    public function testALineSoldByMeasureIsListedAtTheCentTheOrderIsCharged(): void
    {
        $order = $this->orderWith([['price' => '14.820000', 'tax' => '0.000000', 'quantity' => 1.25]], '0', '0');

        $batched = $this->orders->findTotalAmountByOrder([(int) $order->getId()]);

        self::assertSame(18.53, $order->getTotalAmount());
        self::assertSame($order->getTotalAmount(), $batched[(int) $order->getId()]);
        self::assertSame('18,53 €', str_replace("\u{a0}", ' ', (string) (new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY))->formatCurrency($batched[(int) $order->getId()], 'EUR')));
    }

    /**
     * A quantity kept with an error past the thousandth is read as it was sold, as the
     * order model reads it: 0.7 kg at 0.75 is 0.525, charged 0.53.
     */
    public function testAQuantityKeptWithABinaryErrorIsReadAsItWasSold(): void
    {
        $order = $this->orderWith([['price' => '0.750000', 'tax' => '0.000000', 'quantity' => 0.69999998]], '0', '0');

        $batched = $this->orders->findTotalAmountByOrder([(int) $order->getId()]);

        self::assertSame($order->getTotalAmount(), $batched[(int) $order->getId()]);
    }

    /**
     * Orders placed before Thelia 2.4 are totalled without any rounding at all,
     * and a shop that has some keeps them frozen on that rule for good: reading an
     * invoice back under a newer rule would restate an amount already paid. The
     * batched read has to honour the pivot the same way the model honours it.
     */
    public function testAnOrderFrozenOnThePreRoundingRuleKeepsItsAmount(): void
    {
        $order = $this->orderWith([
            ['price' => '12.345600', 'tax' => '2.469100', 'quantity' => 3],
            ['price' => '0.999900', 'tax' => '0.199980', 'quantity' => 7],
        ], '1.500000', '4.900000');

        $this->givenTheLegacyRoundingPivotAt((int) $order->getId());

        $batched = $this->orders->findTotalAmountByOrder([(int) $order->getId()]);

        self::assertSame(
            number_format($order->getTotalAmount(), 2, '.', ''),
            number_format($batched[(int) $order->getId()], 2, '.', ''),
        );
    }

    /**
     * Under the rounding of sums the line total is rounded, and the quantity is a binary
     * float: 1.25 x 14.82 is 18.52499… as a double, which ROUND() brings down to 18.52 where
     * the order charges 18.53. The list, its amount filter and its sort on the amount read
     * the cent the order model answers.
     */
    public function testALineSoldByMeasureUnderTheRoundingOfSumsIsListedAtTheCentTheOrderIsCharged(): void
    {
        $this->givenConfig(self::ROUNDING_MODE, '2');
        $this->givenConfig(self::FLOAT_LINE_ROUNDING_PIVOT, '0');

        $order = $this->orderWith([['price' => '14.820000', 'tax' => '0.000000', 'quantity' => 1.25]], '0', '0');
        $orderId = (int) $order->getId();

        self::assertSame(18.53, $order->getTotalAmount());
        self::assertSame(18.53, $this->orders->findTotalAmountByOrder([$orderId])[$orderId]);
        self::assertSame('18.53', $this->sqlTotalOf($orderId));
        self::assertContains($orderId, $this->orderIdsOfAtLeast(18.53), 'The amount filter has to keep the order from its own amount.');
    }

    /**
     * The orders placed before the 3.4 upgrade were invoiced on the floating point
     * figure, and Order::getTotalAmount() keeps reading them that way: 18.52.
     */
    public function testAnOrderPlacedBeforeTheLineTotalsWereRoundedAsDecimalsKeepsItsAmount(): void
    {
        $this->givenConfig(self::ROUNDING_MODE, '2');

        $order = $this->orderWith([['price' => '14.820000', 'tax' => '0.000000', 'quantity' => 1.25]], '0', '0');
        $orderId = (int) $order->getId();

        $this->givenConfig(self::FLOAT_LINE_ROUNDING_PIVOT, (string) $orderId);
        $order->reload(true, $this->getPropelConnection());

        self::assertSame(18.52, $order->getTotalAmount());
        self::assertSame(18.52, $this->orders->findTotalAmountByOrder([$orderId])[$orderId]);
        self::assertSame('18.52', $this->sqlTotalOf($orderId));
        self::assertNotContains($orderId, $this->orderIdsOfAtLeast(18.53));
    }

    /**
     * An order placed while order_product.promo_price was a text was totalled with its unit
     * prices read as floating point numbers: 1.005 rounds to 1.00 there, and 3 x 1.005 is
     * charged 3.00, not 3.03.
     */
    public function testAnOrderPlacedWhileThePromotionalPriceWasATextKeepsItsAmount(): void
    {
        $this->skipUnlessTheCoreHasTheRule();
        $this->givenConfig(self::ROUNDING_MODE, '1');

        $order = $this->orderWith([['price' => '1.005000', 'tax' => '0.000000', 'quantity' => 3]], '0', '0');
        $orderId = (int) $order->getId();

        $this->givenConfig(self::TEXT_PROMO_PRICE_PIVOT, (string) $orderId);
        $order->reload(true, $this->getPropelConnection());

        self::assertSame(3.0, $order->getTotalAmount());
        self::assertSame(3.0, $this->orders->findTotalAmountByOrder([$orderId])[$orderId]);
        self::assertSame('3.00', $this->sqlTotalOf($orderId));
    }

    /**
     * Under the sum of roundings, the order rounds each tax of a line to the cent before
     * adding them: a line of 10.00 with two taxes of 0.125 is charged 10.26, and the order
     * sheet shows it so.
     */
    public function testALineTaxedTwiceIsShownAtTheCentTheOrderCharges(): void
    {
        $this->skipUnlessTheCoreHasTheRule();
        $this->givenConfig(self::ROUNDING_MODE, '1');

        $order = $this->orderWith([['price' => '10.000000', 'tax' => '0.125000', 'quantity' => 1]], '0', '0');
        foreach ($order->getOrderProducts() as $orderProduct) {
            (new OrderProductTax())
                ->setOrderProductId($orderProduct->getId())
                ->setTitle('Second VAT')
                ->setAmount('0.125000')
                ->setPromoAmount('0.125000')
                ->save($this->getPropelConnection());
        }
        $order->reload(true, $this->getPropelConnection());
        $orderId = (int) $order->getId();

        $line = OrderRoundingRule::lineTotalsOfOrder($orderId, 10.0, [0.125, 0.125], 1.0);

        self::assertSame(10.26, $order->getTotalAmount());
        self::assertSame(10.26, $line['ttc']);
        self::assertSame(0.26, $line['tax']);
        self::assertSame('10.26', $this->sqlTotalOf($orderId));
    }

    public function testAWholePageOfAmountsCostsOneQuery(): void
    {
        $orders = [
            $this->orderWith([['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 1]], '0', '0'),
            $this->orderWith([['price' => '3.333300', 'tax' => '0.666660', 'quantity' => 9]], '1.500000', '4.900000'),
            $this->orderWith([], '0', '0'),
        ];
        $orderIds = array_map(static fn (Order $order): int => (int) $order->getId(), $orders);

        $totals = [];
        // The first read of the configuration loads the whole table: the set-up of the test, not the cost of a page.
        ConfigQuery::read(self::ROUNDING_MODE);
        $queries = QueryCounter::count(function () use ($orderIds, &$totals): void {
            $totals = $this->orders->findTotalAmountByOrder($orderIds);
        });

        self::assertSame(1, $queries, 'One query for the page, not one per order.');
        self::assertCount(3, $totals);
    }

    public function testAWholePageOfLineCountsCostsOneQuery(): void
    {
        $twoLines = $this->orderWith([
            ['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 1],
            ['price' => '20.000000', 'tax' => '4.000000', 'quantity' => 1],
        ], '0', '0');
        $noLine = $this->orderWith([], '0', '0');

        $counts = [];
        $queries = QueryCounter::count(function () use ($twoLines, $noLine, &$counts): void {
            $counts = $this->orders->countItemsByOrder([(int) $twoLines->getId(), (int) $noLine->getId()]);
        });

        self::assertSame(1, $queries, 'One query for the page, not one per order.');
        self::assertSame(2, $counts[(int) $twoLines->getId()]);
        self::assertArrayNotHasKey((int) $noLine->getId(), $counts, 'An order with no line has no row to count.');
    }

    public function testTheLineCountsMatchAnOrderByOrderCount(): void
    {
        $orders = [
            $this->orderWith([['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 1]], '0', '0'),
            $this->orderWith([
                ['price' => '10.000000', 'tax' => '2.000000', 'quantity' => 1],
                ['price' => '11.000000', 'tax' => '2.200000', 'quantity' => 1],
                ['price' => '12.000000', 'tax' => '2.400000', 'quantity' => 1],
            ], '0', '0'),
        ];
        $orderIds = array_map(static fn (Order $order): int => (int) $order->getId(), $orders);

        $expected = [];
        foreach ($orderIds as $orderId) {
            $expected[$orderId] = $this->orders->countItemsForOrder($orderId);
        }

        $counts = $this->orders->countItemsByOrder($orderIds);
        ksort($counts);

        self::assertSame($expected, $counts);
    }

    public function testAnEmptyPageAsksNothing(): void
    {
        $queries = QueryCounter::count(function (): void {
            $this->orders->findTotalAmountByOrder([]);
            $this->orders->countItemsByOrder([]);
        });

        self::assertSame(0, $queries);
    }

    private function skipUnlessTheCoreHasTheRule(): void
    {
        if (!OrderRoundingRule::coreHasTheRule()) {
            self::markTestSkipped('The core predates Thelia\\Domain\\Pricing\\OrderLineRounding: the template reads its own copy of the rule.');
        }
    }

    private function givenTheLegacyRoundingPivotAt(int $orderId): void
    {
        ConfigQuery::write(self::LEGACY_ROUNDING_PIVOT, (string) $orderId);
        $this->legacyPivotWritten = true;
    }

    private function givenConfig(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->configBefore)) {
            $this->configBefore[$name] = ConfigQuery::create()->findOneByName($name)?->getValue();
        }

        ConfigQuery::write($name, $value);
    }

    /**
     * The amount the list sorts and filters on, read the way the list reads it.
     */
    private function sqlTotalOf(int $orderId): string
    {
        $statement = $this->getPropelConnection()->prepare(
            'SELECT ROUND('.OrderFilters::totalAmountSqlExpression().', 2) FROM `order` WHERE '.OrderTableMap::COL_ID.' = ?',
        );
        $statement->execute([$orderId]);

        return number_format((float) $statement->fetchColumn(), 2, '.', '');
    }

    /**
     * @return list<int>
     */
    private function orderIdsOfAtLeast(float $amount): array
    {
        $query = (new OrderFilters(minAmount: $amount))->applyTo(OrderQuery::create());

        return array_map('intval', $query->select([OrderTableMap::COL_ID])->find($this->getPropelConnection())->getData());
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function orderWith(array $lines, string $discount, string $postage): Order
    {
        $order = $this->createFixtureFactory()->order(null, ['postage' => $postage]);
        $order->setDiscount($discount)->save($this->getPropelConnection());

        foreach ($lines as $index => $line) {
            $inPromo = (bool) ($line['promo'] ?? false);

            $orderProduct = new OrderProduct();
            $orderProduct
                ->setOrderId($order->getId())
                ->setProductRef('REF-'.$index)
                ->setProductSaleElementsRef('PSE-'.$index)
                ->setTitle('Line '.$index)
                ->setQuantity((float) $line['quantity'])
                ->setPrice((string) $line['price'])
                ->setPromoPrice((string) ($line['promoPrice'] ?? $line['price']))
                ->setWasNew(0)
                ->setWasInPromo($inPromo ? 1 : 0)
                ->save($this->getPropelConnection());

            $orderProductTax = new OrderProductTax();
            $orderProductTax
                ->setOrderProductId($orderProduct->getId())
                ->setTitle('VAT')
                ->setAmount((string) $line['tax'])
                ->setPromoAmount((string) ($line['promoTax'] ?? $line['tax']))
                ->save($this->getPropelConnection());
        }

        // Order::getTotalAmount() caches its per-order query result for the process,
        // and the lines are written after the order exists. Read the order fresh so
        // the model computes on the lines this test just added.
        $order->reload(true, $this->getPropelConnection());

        return $order;
    }
}
