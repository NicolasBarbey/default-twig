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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The items of the order sheet read in five columns: the product, the quantity, the shipment,
 * the unit price and the total, each amount written as an addition (excluding tax, the tax, including tax)
 * with the one the customer saw in bold.
 */
final class OrderItemsAmountsTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin(['locale' => 'en_US']);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheItemsReadInFiveColumns(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $this->taxedLine($order, '100.000000', '20.000000', 2.0);

        $crawler = $this->sheet($order);

        $headers = $crawler->filter('[data-testid="datatable-order-items-table"] thead th')->each(static fn (Crawler $th): string => trim($th->text()));
        self::assertSame(['Product', 'Qty', 'Shipment', 'Unit price', 'Total'], array_values(array_filter($headers, static fn (string $text): bool => $text !== '' && $text !== 'Details')));
    }

    public function testEachAmountIsAnAdditionWithTheTaxedAmountInBoldForACustomerWhoSawTaxedPrices(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $line = $this->taxedLine($order, '100.000000', '20.000000', 2.0);

        $row = $this->rowOf($this->sheet($order), $line);

        self::assertStringContainsString('100.00', $row->filter('[data-testid="order-item-unit-ht"]')->text());
        self::assertStringContainsString('20.00', $row->filter('[data-testid="order-item-unit-tax"]')->text());
        self::assertStringContainsString('120.00', $row->filter('[data-testid="order-item-unit-ttc"]')->text());
        self::assertStringContainsString('200.00', $row->filter('[data-testid="order-item-line-ht"]')->text());
        self::assertStringContainsString('40.00', $row->filter('[data-testid="order-item-line-tax"]')->text());
        self::assertStringContainsString('240.00', $row->filter('[data-testid="order-item-line-ttc"]')->text());

        self::assertStringContainsString('fw-semibold', (string) $row->filter('[data-testid="order-item-line-ttc"]')->attr('class'), 'The customer saw taxed prices: the taxed amount is the bold one.');
        self::assertStringNotContainsString('fw-semibold', (string) $row->filter('[data-testid="order-item-line-ht"]')->attr('class'));
        self::assertSame('French VAT 20%', $row->filter('[data-testid="order-item-unit-tax"] > span[data-bs-toggle="tooltip"]')->attr('title'), 'The tax names its rule on hover.');
    }

    public function testTheUntaxedAmountIsInBoldWhenTheCustomerGroupShowedPricesExcludingTax(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $order->setCustomerGroupDisplayTaxedPrice(0)->save($this->getPropelConnection());
        $line = $this->taxedLine($order, '100.000000', '20.000000', 1.0);

        $crawler = $this->sheet($order);
        $row = $this->rowOf($crawler, $line);

        self::assertStringContainsString('fw-semibold', (string) $row->filter('[data-testid="order-item-unit-ht"]')->attr('class'), 'A business customer saw prices excluding tax: that is the bold one.');
        self::assertStringNotContainsString('fw-semibold', (string) $row->filter('[data-testid="order-item-unit-ttc"]')->attr('class'));
    }

    public function testALineSoldInPromotionShowsThePriceItWouldHaveBeenSoldAt(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $promo = $this->taxedLine($order, '100.000000', '20.000000', 1.0, promoPrice: '80.000000', promoTax: '16.000000');
        $regular = $this->taxedLine($order, '50.000000', '10.000000', 1.0);

        $crawler = $this->sheet($order);
        $promoRow = $this->rowOf($crawler, $promo);

        self::assertCount(1, $promoRow->filter('[data-testid="order-item-promo"]'));
        self::assertStringContainsString('120.00', $promoRow->filter('[data-testid="order-item-promo"] del')->text(), 'The struck price is the taxed one the customer would have paid.');
        self::assertStringContainsString('80.00', $promoRow->filter('[data-testid="order-item-unit-ht"]')->text(), 'The unit price is the price the line was sold at.');
        self::assertStringContainsString('96.00', $promoRow->filter('[data-testid="order-item-unit-ttc"]')->text());
        self::assertCount(0, $this->rowOf($crawler, $regular)->filter('[data-testid="order-item-promo"]'));
    }

    public function testTheTotalsOfTheOrderReadOnlyInTheTotalsCard(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $this->taxedLine($order, '100.000000', '20.000000', 2.0);
        $this->taxedLine($order, '50.000000', '10.000000', 1.0);

        $crawler = $this->sheet($order);

        self::assertCount(0, $crawler->filter('[data-testid="order-items-subtotal"]'), 'No subtotal under the items: under a paged table it would read as the total of the page.');
        self::assertStringContainsString('250.00', $crawler->filter('[data-testid="order-totals-subtotal-ht"]')->text());
    }

    private function sheet(Order $order): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function rowOf(Crawler $crawler, OrderProduct $line): Crawler
    {
        $row = $crawler->filter('tr[data-order-product-id="'.$line->getId().'"]');
        self::assertCount(1, $row);

        return $row;
    }

    private function taxedLine(Order $order, string $price, string $tax, float $quantity, ?string $promoPrice = null, ?string $promoTax = null): OrderProduct
    {
        $line = $this->factory->orderProduct($order, ['price' => $price, 'promoPrice' => $promoPrice ?? $price, 'quantity' => $quantity]);
        $line->setWasInPromo(null === $promoPrice ? 0 : 1);
        $line->setTaxRuleTitle('French VAT 20%');
        $line->save($this->getPropelConnection());

        (new OrderProductTax())
            ->setOrderProductId((int) $line->getId())
            ->setTitle('French VAT 20%')
            ->setAmount($tax)
            ->setPromoAmount($promoTax ?? $tax)
            ->save($this->getPropelConnection());

        return $line;
    }
}
