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
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The order badges of the sidebar, which every back-office page draws.
 *
 * They used to cost one count query per status plus one title query per status,
 * on every screen, and the dashboard asked for the very same figures again a few
 * lines later. The budget is what these tests hold on to; the figures themselves
 * are checked against a status-by-status count.
 */
final class OrderStatusCountersTest extends IntegrationTestCase
{
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new OrderRepository();
    }

    public function testTheCountersCostOneCountQueryAndOneStatusReadWhateverTheNumberOfStatuses(): void
    {
        $this->givenOrdersInSeveralStatuses();

        $counters = [];
        $queries = QueryCounter::count(function () use (&$counters): void {
            $counters = $this->orders->findStatusesWithCounts('en_US');
        });

        self::assertGreaterThan(1, \count($counters), 'Several statuses host an order.');
        self::assertSame(2, $queries, 'One grouped count, one status read with its titles.');
    }

    public function testTheDashboardReadsTheFiguresTheSidebarAlreadyPaidFor(): void
    {
        $this->givenOrdersInSeveralStatuses();
        $this->orders->findStatusesWithCounts('en_US');

        $queries = QueryCounter::count(function (): void {
            $this->orders->getStatusBreakdown('en_US');
            $this->orders->findStatusesWithCounts('en_US');
        });

        self::assertSame(0, $queries, 'The counters are read once per locale and per request.');
    }

    public function testTheCountsMatchAStatusByStatusCount(): void
    {
        $this->givenOrdersInSeveralStatuses();

        $expected = [];
        foreach (OrderStatusQuery::create()->orderById()->find() as $status) {
            $count = OrderQuery::create()->filterByStatusId((int) $status->getId())->count();
            if ($count > 0) {
                $expected[(int) $status->getId()] = $count;
            }
        }

        $counters = [];
        foreach ($this->orders->findStatusesWithCounts('en_US') as $row) {
            $counters[$row['id']] = $row['count'];
        }

        self::assertSame($expected, $counters);
    }

    public function testAStatusHostingNoOrderStaysOffTheSidebar(): void
    {
        $this->givenOrdersInSeveralStatuses();

        $empty = $this->createFixtureFactory()->orderStatus([
            'code' => 'awaiting-the-warehouse',
            'equivalentCode' => OrderStatus::CODE_PROCESSING,
        ]);

        $ids = array_column($this->orders->findStatusesWithCounts('en_US'), 'id');

        self::assertNotContains((int) $empty->getId(), $ids);
    }

    private function givenOrdersInSeveralStatuses(): void
    {
        $factory = $this->createFixtureFactory();

        foreach ([OrderStatus::CODE_NOT_PAID, OrderStatus::CODE_PAID, OrderStatus::CODE_SENT] as $code) {
            $factory->order(null, ['statusCode' => $code]);
        }

        $factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
    }
}
