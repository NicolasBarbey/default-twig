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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Order;

use BackOfficeDefaultTwigBundle\Service\Order\OrderListRowPresenter;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

final class OrderListRowPresenterTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
    }

    public function testTheAmountOfAnOrderInDollarsIsShownInDollars(): void
    {
        $dollar = $this->factory->currency(['code' => 'USD', 'symbol' => '$', 'rate' => 1.1, 'byDefault' => 0]);
        $order = $this->factory->order(
            $this->factory->customer($this->factory->customerTitle()),
            ['statusCode' => OrderStatus::CODE_PAID, 'postage' => '1234.50'],
        );
        $order->setCurrencyId($dollar->getId())->save($this->getPropelConnection());
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $row = $presenter->present($order, 'en_US', totals: [(int) $order->getId() => 1234.5]);

        self::assertSame('$1,234.50', $row['amount']);
        self::assertSame("1\u{202F}234,50\u{a0}\$US", $presenter->present($order, 'fr_FR', totals: [(int) $order->getId() => 1234.5])['amount']);
    }
}
