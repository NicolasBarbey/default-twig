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

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * What the return list costs.
 *
 * The line count of every row is the figure that invites one query per row: it
 * is read for the whole page at once, and this test states that budget so a
 * later change cannot quietly go back to reading it return by return.
 */
final class OrderReturnListQueriesTest extends IntegrationTestCase
{
    private OrderReturnRepository $returns;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->returns = new OrderReturnRepository(new OrderAccess(new AdminSaleUnitScope()));
        $this->factory = $this->createFixtureFactory();
    }

    public function testAWholePageOfLineCountsCostsOneQuery(): void
    {
        $twoLines = $this->returnWith(2);
        $oneLine = $this->returnWith(1);
        $noLine = $this->returnWith(0);

        $counts = [];
        $queries = QueryCounter::count(function () use ($twoLines, $oneLine, $noLine, &$counts): void {
            $counts = $this->returns->countLinesByReturn([
                (int) $twoLines->getId(),
                (int) $oneLine->getId(),
                (int) $noLine->getId(),
            ]);
        });

        self::assertSame(1, $queries, 'One query for the page, not one per return.');
        self::assertSame(2, $counts[(int) $twoLines->getId()]);
        self::assertSame(1, $counts[(int) $oneLine->getId()]);
        self::assertArrayNotHasKey(
            (int) $noLine->getId(),
            $counts,
            'A return with no line has no row to count.',
        );
    }

    public function testAnEmptyPageAsksNothing(): void
    {
        $counts = [];
        $queries = QueryCounter::count(function () use (&$counts): void {
            $counts = $this->returns->countLinesByReturn([]);
        });

        self::assertSame(0, $queries);
        self::assertSame([], $counts);
    }

    public function testTheStatusBreakdownIsReadOncePerLocale(): void
    {
        $this->returnWith(1);

        $first = QueryCounter::count(function (): void {
            $this->returns->findStatusesWithCounts('en_US');
        });
        $second = QueryCounter::count(function (): void {
            $this->returns->findStatusesWithCounts('en_US');
        });

        self::assertGreaterThan(0, $first);
        self::assertSame(0, $second, 'The breakdown is memoised for the request.');
    }

    private function returnWith(int $lineCount): OrderReturn
    {
        $connection = $this->getPropelConnection();

        $customer = $this->factory->customer($this->factory->customerTitle());
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);

        $orderReturn = (new OrderReturn())
            ->setOrder($order)
            ->setCustomer($customer)
            ->setOrderReturnStatus(
                OrderReturnStatusQuery::create()->findOneByCode(OrderReturnStatus::CODE_REQUESTED),
            );
        $orderReturn->save($connection);

        for ($i = 0; $i < $lineCount; ++$i) {
            (new OrderReturnLine())
                ->setOrderReturn($orderReturn)
                ->setOrderProduct($this->orderProduct($order))
                ->setQuantity(1.0)
                ->save($connection);
        }

        return $orderReturn;
    }

    private function orderProduct(Order $order): OrderProduct
    {
        $orderProduct = (new OrderProduct())
            ->setOrderId((int) $order->getId())
            ->setProductRef('REF-'.uniqid())
            ->setProductSaleElementsRef('PSE-'.uniqid())
            ->setTitle('A returnable product')
            ->setQuantity(1.0)
            ->setPrice('10.000000')
            ->setPromoPrice('10.000000')
            ->setWasNew(1)
            ->setWasInPromo(0)
            ->setVirtual(0);
        $orderProduct->save($this->getPropelConnection());

        return $orderProduct;
    }
}
