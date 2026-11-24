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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Shipping\DTO\ShipmentLine;
use Thelia\Domain\Shipping\Service\OrderShipmentManager;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderShipment;
use Thelia\Model\OrderShipmentQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The shipments tab of the order sheet: what is left to ship per line, the parcel
 * the merchant composes from it, what leaving does to the order, the delivery note of
 * one parcel, and who may do all this.
 */
final class OrderShipmentBackOfficeTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        if (!class_exists(OrderShipmentManager::class)) {
            self::markTestSkipped('The core of this checkout predates shipments.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheSheetShowsWhatIsLeftToShipAndOffersTheForm(): void
    {
        $this->loginFullAdmin();
        [$order, $first, $second] = $this->paidOrderOfTwoLines();
        $this->shipped($order, $first, 1.0, 'FIRST-PARCEL');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId().'?tab=shipments');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // The table writes its own data-testid on the row before the one the sheet asks for; the line id is what singles the row out.
        $row = $crawler->filter('tr[data-order-product-id="'.$first->getId().'"]');
        self::assertSame('3', $this->quantityOf($row), 'The quantity ordered heads the cell.');
        self::assertSame('1 / 3', $this->shippedOf($row), 'One unit of three has left.');
        self::assertSame('warning', $row->filter('[data-testid="order-item-shipped"]')->attr('data-tone'));
        $untouched = $crawler->filter('tr[data-order-product-id="'.$second->getId().'"]');
        self::assertSame('0 / 2', $this->shippedOf($untouched), 'A line no parcel has taken from reads as nothing shipped yet.');
        self::assertSame('danger', $untouched->filter('[data-testid="order-item-shipped"]')->attr('data-tone'));

        self::assertCount(1, $crawler->filter('[data-testid^="order-shipment-row-"]'));
        self::assertStringContainsString('FIRST-PARCEL', $crawler->filter('[data-testid="order-shipments-card"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="order-shipment-line-'.$first->getId().'"]'));
        self::assertCount(1, $crawler->filter('[data-testid="order-shipment-line-'.$second->getId().'"]'));
        self::assertSame('2', $crawler->filter('#shipment-line-'.$first->getId())->attr('value'), 'The form proposes what is left.');
        self::assertStringContainsString('active', (string) $crawler->filter('#tab-shipments-toggle')->attr('class'), 'The tab asked for is the one opened.');
    }

    public function testALineWhollyShippedSaysSoUnderItsQuantity(): void
    {
        $this->loginFullAdmin();
        [$order, , $second] = $this->paidOrderOfTwoLines();
        $this->shipped($order, $second, 2.0, 'WHOLE-PARCEL');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $row = $crawler->filter('tr[data-order-product-id="'.$second->getId().'"]');
        self::assertSame('2', $this->quantityOf($row));
        self::assertSame('2 / 2', $this->shippedOf($row));
        self::assertSame('success', $row->filter('[data-testid="order-item-shipped"]')->attr('data-tone'), 'Nothing is left: the line reads as shipped.');
    }

    public function testWhatWaitsInADraftParcelIsNotShippedYet(): void
    {
        $this->loginFullAdmin();
        [$order, $first, $second] = $this->paidOrderOfTwoLines();
        $this->shipped($order, $first, 1.0, 'FIRST-PARCEL');
        $manager = $this->getService(OrderShipmentManager::class);
        $manager->open($order, [new ShipmentLine((int) $first->getId(), 2.0), new ShipmentLine((int) $second->getId(), 2.0)]);

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $partly = $crawler->filter('tr[data-order-product-id="'.$first->getId().'"]');
        self::assertSame('1 / 3', $this->shippedOf($partly), 'One unit left, the two others wait in a draft: nothing is left to put in a parcel, yet the line is not shipped.');
        self::assertSame('warning', $partly->filter('[data-testid="order-item-shipped"]')->attr('data-tone'));

        $drafted = $crawler->filter('tr[data-order-product-id="'.$second->getId().'"]');
        self::assertSame('0 / 2', $this->shippedOf($drafted), 'A line wholly in a draft has not left yet: it does not read as shipped.');
        self::assertSame('danger', $drafted->filter('[data-testid="order-item-shipped"]')->attr('data-tone'));
    }

    public function testNothingShippedOnAnOrderThatDoesNotWaitToBeShippedReadsInGrey(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_CANCELED]);
        $line = $this->factory->orderProduct($order, ['quantity' => 2.0, 'title' => 'Cancelled widget']);

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $row = $crawler->filter('tr[data-order-product-id="'.$line->getId().'"]');
        self::assertSame('0 / 2', $this->shippedOf($row));
        self::assertSame('secondary', $row->filter('[data-testid="order-item-shipped"]')->attr('data-tone'), 'A cancelled order waits for no parcel: no warning.');
    }

    public function testAVirtualLineNoParcelCanTakeSaysNothingAboutShipping(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $download = $this->factory->orderProduct($order, ['quantity' => 1.0, 'title' => 'Downloadable guide', 'virtual' => 1]);

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertCount(0, $crawler->filter('tr[data-order-product-id="'.$download->getId().'"] [data-testid="order-item-shipped"]'));
    }

    public function testAParcelLineShowsItsQuantityWithTheDecimalsAndTheUnitOfTheOrderLine(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $line = $this->factory->orderProduct($order, ['quantity' => 3.0, 'title' => 'Weighed cheese']);
        $line->setQuantityDecimals(2);
        $line->setUnitCode('kg');
        $line->save($this->getPropelConnection());
        $this->shipped($order, $line, 1.25, 'WEIGHED-PARCEL');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId().'?tab=shipments');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $parcel = $crawler->filter('[data-testid^="order-shipment-row-"]')->text();
        self::assertMatchesRegularExpression('/1[.,]25\s*kg\s*×/u', $parcel, 'The parcel line is written like the order line: decimals and unit.');
    }

    public function testWhatIsShippedAndLeftOfALineSoldByMeasureReadsWithItsDecimalsAndUnit(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $line = $this->factory->orderProduct($order, ['quantity' => 3.0, 'title' => 'Weighed cheese']);
        $line->setQuantityDecimals(2);
        $line->setUnitCode('kg');
        $line->save($this->getPropelConnection());
        $this->shipped($order, $line, 1.25, 'WEIGHED-PARCEL');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId().'?tab=shipments');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $row = $crawler->filter('tr[data-order-product-id="'.$line->getId().'"]');
        self::assertMatchesRegularExpression('/^1[.,]25\s*\/\s*3[.,]00\s*kg$/u', $this->shippedOf($row));

        $composed = $crawler->filter('[data-testid="order-shipment-line-'.$line->getId().'"]');
        self::assertMatchesRegularExpression('/^1[.,]75\s*kg$/u', trim($composed->filter('td')->eq(1)->text()), 'The parcel form reads what is left like the order line.');
        self::assertSame('1.75', $composed->filter('input')->attr('value'), 'The field keeps the exact quantity a browser submits.');
        self::assertSame('1.75', $composed->filter('input')->attr('max'));
    }

    public function testWhatIsLeftOfALineSoldByThePieceWithAContentUnitReadsAsAWholeNumber(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $line = $this->factory->orderProduct($order, ['quantity' => 6.0, 'title' => 'Bottle of wine']);
        $line->setQuantityDecimals(0);
        $line->setUnitCode('l');
        $line->save($this->getPropelConnection());
        $this->shipped($order, $line, 2.0, 'BOTTLE-PARCEL');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId().'?tab=shipments');

        $row = $crawler->filter('tr[data-order-product-id="'.$line->getId().'"]');
        self::assertSame('2 / 6', $this->shippedOf($row), 'A bottle sold by the piece is counted, not measured.');
        self::assertSame('4', trim($crawler->filter('[data-testid="order-shipment-line-'.$line->getId().'"] td')->eq(1)->text()));
    }

    public function testComposingAParcelThatLeavesMovesTheOrderToPartiallyShipped(): void
    {
        $this->loginFullAdmin();
        [$order, $first, $second] = $this->paidOrderOfTwoLines();

        $this->client->request('POST', '/admin/order/update/'.$order->getId().'/shipment', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
            'line' => [(string) $first->getId() => '3', (string) $second->getId() => '0'],
            'delivery_ref' => 'BO-PARCEL-1',
            'ship' => '1',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('tab=shipments', (string) $this->client->getResponse()->headers->get('Location'));

        $shipment = $this->theOnlyShipmentOf($order);
        self::assertSame(OrderShipment::STATUS_SHIPPED, $shipment->getStatus());
        self::assertSame('BO-PARCEL-1', $shipment->getDeliveryRef());
        self::assertSame([(int) $first->getId() => 3.0], $this->linesOf($shipment), 'A line left at zero is not in the parcel.');
        self::assertSame(OrderStatus::CODE_PARTIALLY_SHIPPED, $this->statusCodeOf($order));
        self::assertSame('BO-PARCEL-1', $this->reloaded($order)->getDeliveryRef());

        $this->client->request('POST', '/admin/order/update/'.$order->getId().'/shipment', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
            'line' => [(string) $second->getId() => '2'],
            'ship' => '1',
        ]);

        self::assertSame(OrderStatus::CODE_SENT, $this->statusCodeOf($order), 'The second parcel takes everything left: the order is sent.');
        self::assertCount(0, $this->client->request('GET', '/admin/order/update/'.$order->getId().'?tab=shipments')->filter('[data-testid="order-shipment-compose-card"]'), 'Nothing left: no form.');
    }

    public function testADraftIsShippedOrCancelledFromTheSheet(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->paidOrderOfTwoLines();

        $this->client->request('POST', '/admin/order/update/'.$order->getId().'/shipment', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
            'line' => [(string) $first->getId() => '1'],
        ]);
        $draft = $this->theOnlyShipmentOf($order);
        self::assertSame(OrderShipment::STATUS_DRAFT, $draft->getStatus());
        self::assertSame(OrderStatus::CODE_PAID, $this->statusCodeOf($order), 'A draft moves nothing.');

        $this->client->request('POST', '/admin/order/shipment/'.$draft->getId().'/cancel', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $draft->reload();
        self::assertSame(OrderShipment::STATUS_CANCELLED, $draft->getStatus());

        $this->client->request('POST', '/admin/order/update/'.$order->getId().'/shipment', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
            'line' => [(string) $first->getId() => '3'],
        ]);
        $second = OrderShipmentQuery::create()->filterByOrderId($order->getId())->filterByStatus(OrderShipment::STATUS_DRAFT)->findOne();
        self::assertNotNull($second);

        $this->client->request('POST', '/admin/order/shipment/'.$second->getId().'/ship', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
            'delivery_ref' => 'LATE-NUMBER',
        ]);
        $second->reload();
        self::assertSame(OrderShipment::STATUS_SHIPPED, $second->getStatus());
        self::assertSame('LATE-NUMBER', $second->getDeliveryRef());
        self::assertSame(OrderStatus::CODE_PARTIALLY_SHIPPED, $this->statusCodeOf($order));
    }

    public function testAParcelBeyondWhatIsLeftIsRefusedWithAMessage(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->paidOrderOfTwoLines();

        $this->client->request('POST', '/admin/order/update/'.$order->getId().'/shipment', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
            'line' => [(string) $first->getId() => '99'],
            'ship' => '1',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, OrderShipmentQuery::create()->filterByOrderId($order->getId())->find());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('left to ship', $crawler->filter('.alert-danger')->text());
    }

    public function testTheDeliveryNoteOfAParcelIsServedAndTheOrderPdfIsUnchanged(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->paidOrderOfTwoLines();
        $shipment = $this->shipped($order, $first, 1.0, 'PDF-PARCEL');

        $this->client->request('GET', '/admin/order/pdf/shipment/'.$shipment->getId().'/0');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('delivery-'.$shipment->getRef().'.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->client->request('GET', '/admin/order/pdf/delivery/'.$order->getId().'/0');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('delivery-'.$order->getRef().'.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAnAdministratorWithTheViewRightOnlyReadsButCannotShip(): void
    {
        $admin = $this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        [$order, $first] = $this->paidOrderOfTwoLines();
        $shipment = $this->shipped($order, $first, 1.0, 'READ-ONLY');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId().'?tab=shipments');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="order-shipment-row-'.$shipment->getId().'"]'));
        self::assertCount(0, $crawler->filter('[data-testid="order-shipment-compose-card"]'), 'No form without the update right.');

        $this->client->request('POST', '/admin/order/update/'.$order->getId().'/shipment', [
            '_token' => $this->tokenOf('/admin/order/update/'.$order->getId()),
            'line' => [(string) $first->getId() => '1'],
            'ship' => '1',
        ]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
        self::assertCount(1, OrderShipmentQuery::create()->filterByOrderId($order->getId())->find(), 'Nothing was written.');

        $this->client->request('GET', '/admin/order/pdf/shipment/'.$shipment->getId().'/0');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The view right prints the note.');
    }

    private function loginFullAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    /**
     * @return array{0: Order, 1: OrderProduct, 2: OrderProduct}
     */
    private function paidOrderOfTwoLines(): array
    {
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);

        return [
            $order,
            $this->factory->orderProduct($order, ['quantity' => 3.0, 'title' => 'Shippable widget']),
            $this->factory->orderProduct($order, ['quantity' => 2.0, 'title' => 'Shippable gadget']),
        ];
    }

    private function shipped(Order $order, OrderProduct $line, float $quantity, string $deliveryRef): OrderShipment
    {
        $manager = $this->getService(OrderShipmentManager::class);

        return $manager->ship($manager->open($order, [new ShipmentLine((int) $line->getId(), $quantity)], deliveryRef: $deliveryRef));
    }

    private function theOnlyShipmentOf(Order $order): OrderShipment
    {
        $shipments = OrderShipmentQuery::create()->filterByOrderId($order->getId())->find();
        self::assertCount(1, $shipments);

        return $shipments->getFirst();
    }

    /**
     * @return array<int, float>
     */
    private function linesOf(OrderShipment $shipment): array
    {
        $lines = [];

        foreach ($shipment->getOrderShipmentItems() as $item) {
            $lines[(int) $item->getOrderProductId()] = (float) $item->getQuantity();
        }

        return $lines;
    }

    private function statusCodeOf(Order $order): ?string
    {
        return OrderStatusQuery::create()->findPk($this->reloaded($order)->getStatusId())?->getCode();
    }

    private function reloaded(Order $order): Order
    {
        $order->reload();

        return $order;
    }

    /**
     * The quantity ordered, at the head of the quantity cell of the row.
     */
    private function quantityOf(Crawler $row): string
    {
        $quantity = $row->filter('[data-testid="datatable-order-items-cell-quantity_html"] [data-testid="order-item-quantity"]');
        self::assertCount(1, $quantity, 'The quantity cell shows the quantity ordered.');

        return trim($quantity->text());
    }

    /**
     * What has left over what was ordered, in the shipping cell of the row.
     */
    private function shippedOf(Crawler $row): string
    {
        $shipped = $row->filter('[data-testid="datatable-order-items-cell-shipping_html"] [data-testid="order-item-shipped"] span[dir="ltr"]');
        self::assertCount(1, $shipped, 'The shipping cell says what has left over what was ordered.');

        return trim(preg_replace('/\s+/u', ' ', $shipped->text()));
    }

    /**
     * The CSRF token the given page renders, so a POST is submitted the way the
     * browser submits it.
     */
    private function tokenOf(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('"%s" renders no CSRF token.', $url));

        return (string) $token->attr('value');
    }
}
