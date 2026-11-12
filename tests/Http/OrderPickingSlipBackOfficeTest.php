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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Administrator\AdministratorSaleUnitsEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Domain\Order\Service\PickingSlipPdfService;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The picking slip from the back office: the order sheet, the list row, the ticked orders and every
 * order of the filters, and who may print it.
 */
final class OrderPickingSlipBackOfficeTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists(PickingSlipPdfService::class)) {
            self::markTestSkipped('The core of this checkout has no picking slip.');
        }

        $themeRoot = $this->getService(TemplateHelperInterface::class)->getActivePdfTemplate()->getAbsolutePath();
        if (!is_file($themeRoot.\DIRECTORY_SEPARATOR.'preparation.html.twig')) {
            self::markTestSkipped('The installed PDF theme has no preparation document.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->actAs($this->factory->admin());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheSheetOffersTheSlipAndServesIt(): void
    {
        $order = $this->orderOf($this->customer());

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="order-picking-slip-link"]'));
        self::assertSame('/admin/order/pdf/preparation/'.$order->getId().'/1', $crawler->filter('[data-testid="order-picking-slip-link"]')->attr('href'));

        $this->client->request('GET', '/admin/order/pdf/preparation/'.$order->getId().'/1');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('inline; filename="preparation-'.$order->getRef().'.pdf"', $response->headers->get('Content-Disposition'));

        $this->client->request('GET', '/admin/order/pdf/preparation/'.$order->getId().'/0');
        self::assertSame('attachment; filename="preparation-'.$order->getRef().'.pdf"', $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTheListOffersTheSlipOnEveryRow(): void
    {
        $order = $this->orderOf($this->customer());

        $crawler = $this->client->request('GET', '/admin/orders?customer_id='.$order->getCustomerId());

        // Inline from the medium breakpoint, and again in the menu of the smaller ones.
        $links = $crawler->filter('a.bo-action--picking-slip');
        self::assertGreaterThan(0, $links->count());
        self::assertSame('/admin/order/pdf/preparation/'.$order->getId().'/1', $links->first()->attr('href'));
    }

    public function testTheTickedOrdersGiveOnePdfOnePagePerOrder(): void
    {
        $customer = $this->customer();
        $ticked = [$this->orderOf($customer), $this->orderOf($customer), $this->orderOf($customer)];
        $notTicked = $this->orderOf($customer);

        $this->client->request('POST', '/admin/orders/picking-slip', [
            '_token' => $this->tokenOfTheList(),
            'order_ids' => array_map(static fn (Order $order): string => (string) $order->getId(), $ticked),
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="preparation-'.date('Y-m-d').'.pdf"', $response->headers->get('Content-Disposition'));
        self::assertSame(3, $this->pageCount((string) $response->getContent()), 'One page per ticked order, and not the one that is not ticked.');
        self::assertNotNull($notTicked);
    }

    public function testTheFilterGivesEveryOrderItMatchesBeyondThePageOnScreen(): void
    {
        $customer = $this->customer();
        // The list shows 25 per page.
        for ($i = 0; $i < 27; ++$i) {
            $this->orderOf($customer);
        }
        $this->orderOf($this->customer());

        $this->client->request('POST', '/admin/orders/picking-slip?customer_id='.$customer->getId(), [
            '_token' => $this->tokenOfTheList(),
            'scope' => 'filter',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame(27, $this->pageCount((string) $response->getContent()));
    }

    public function testTheListOffersTheFilterSlipWithTheFiltersInItsAction(): void
    {
        $order = $this->orderOf($this->customer());

        $crawler = $this->client->request('GET', '/admin/orders?customer_id='.$order->getCustomerId());

        $form = $crawler->filter('[data-testid="order-picking-slip-filter-form"]');
        self::assertCount(1, $form);
        self::assertSame('/admin/orders/picking-slip?customer_id='.$order->getCustomerId(), $form->attr('action'));
        self::assertCount(1, $crawler->filter('[data-testid="order-picking-slip-selection-form"]'));
    }

    public function testAFilterOverTheLimitIsRefusedWithAMessage(): void
    {
        ConfigQuery::write(PickingSlipPdfService::CONFIG_MAX_ORDERS, '2');
        $customer = $this->customer();
        foreach ([1, 2, 3] as $ignored) {
            $this->orderOf($customer);
        }

        $this->client->request('POST', '/admin/orders/picking-slip?customer_id='.$customer->getId(), [
            '_token' => $this->tokenOfTheList(),
            'scope' => 'filter',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('more than 2 orders', $crawler->filter('.alert-warning')->text());
    }

    public function testASelectionOverTheLimitIsRefusedWithAMessage(): void
    {
        ConfigQuery::write(PickingSlipPdfService::CONFIG_MAX_ORDERS, '2');
        $customer = $this->customer();
        $ids = [];
        foreach ([1, 2, 3] as $ignored) {
            $ids[] = (string) $this->orderOf($customer)->getId();
        }

        $this->client->request('POST', '/admin/orders/picking-slip', ['_token' => $this->tokenOfTheList(), 'order_ids' => $ids]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('holds 2 at most', $crawler->filter('.alert-warning')->text());
    }

    public function testAnEmptySelectionIsAnsweredByAMessage(): void
    {
        $this->client->request('POST', '/admin/orders/picking-slip', ['_token' => $this->tokenOfTheList()]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('No order to print', $crawler->filter('.alert-warning')->text());
    }

    public function testAForgedTokenProducesNoPdf(): void
    {
        $order = $this->orderOf($this->customer());

        $this->client->request('POST', '/admin/orders/picking-slip', ['_token' => 'forged', 'order_ids' => [(string) $order->getId()]]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Invalid security token', $crawler->filter('.alert-danger')->text());
    }

    public function testATokenInTheUrlOnlyProducesNoPdf(): void
    {
        $order = $this->orderOf($this->customer());

        $this->client->request('POST', '/admin/orders/picking-slip?_token='.$this->tokenOfTheList(), ['order_ids' => [(string) $order->getId()]]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testWithoutTheViewRightNothingIsServed(): void
    {
        $order = $this->orderOf($this->customer());
        $token = $this->tokenOfTheList();
        $this->actAs($this->restricted([AdminResources::CUSTOMER => [AccessManager::VIEW]], []));

        $this->client->request('GET', '/admin/order/pdf/preparation/'.$order->getId().'/1');
        self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->client->request('POST', '/admin/orders/picking-slip', ['_token' => $token, 'order_ids' => [(string) $order->getId()]]);
        self::assertNotSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
    }

    public function testTheViewRightAloneIsEnoughAndTheListOffersTheSelection(): void
    {
        $order = $this->orderOf($this->customer());
        $this->actAs($this->restricted([AdminResources::ORDER => [AccessManager::VIEW]], []));

        $crawler = $this->client->request('GET', '/admin/orders?customer_id='.$order->getCustomerId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="order-picking-slip-selection-form"]'));
        self::assertCount(0, $crawler->filter('[data-testid="order-bulk-status-form"]'), 'No status change without the update right.');

        $this->client->request('POST', '/admin/orders/picking-slip', [
            '_token' => $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'order_ids' => [(string) $order->getId()],
        ]);
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testAnOrderOutOfTheReachOfTheAdministratorDoesNotExistForTheSlip(): void
    {
        $north = $this->saleUnit('north');
        $south = $this->saleUnit('south');
        $customer = $this->customer();
        $northOrder = $this->orderOf($customer);
        $northOrder->setSaleUnitId($north->getId())->save($this->getPropelConnection());
        $southOrder = $this->orderOf($customer);
        $southOrder->setSaleUnitId($south->getId())->save($this->getPropelConnection());

        $admin = $this->restricted([AdminResources::ORDER => [AccessManager::VIEW]], [$north]);
        $this->actAs($admin);
        $token = $this->tokenOfTheList();

        $this->client->request('GET', '/admin/order/pdf/preparation/'.$southOrder->getId().'/1');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/admin/order/pdf/preparation/'.$northOrder->getId().'/1');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/orders/picking-slip', ['_token' => $token, 'order_ids' => [(string) $northOrder->getId(), (string) $southOrder->getId()]]);
        self::assertSame(1, $this->pageCount((string) $this->client->getResponse()->getContent()), 'The order of the other unit is dropped.');
        self::assertStringContainsString('preparation-'.$northOrder->getRef().'.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->client->request('POST', '/admin/orders/picking-slip?customer_id='.$customer->getId(), ['_token' => $token, 'scope' => 'filter']);
        self::assertSame(1, $this->pageCount((string) $this->client->getResponse()->getContent()), 'The filter does not reach it either.');
    }

    private function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function orderOf(Customer $customer): Order
    {
        $order = $this->factory->order($customer);
        $this->factory->orderProduct($order, ['title' => 'Widget']);

        return $order;
    }

    /**
     * @param array<string, list<string>> $grants
     * @param list<SaleUnit>              $saleUnits
     */
    private function restricted(array $grants, array $saleUnits): \Thelia\Model\Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        if ([] !== $saleUnits) {
            $this->getService(EventDispatcherInterface::class)->dispatch(
                new AdministratorSaleUnitsEvent((int) $admin->getId(), array_map(static fn (SaleUnit $unit): int => (int) $unit->getId(), $saleUnits)),
                TheliaEvents::ADMINISTRATOR_UPDATE_SALE_UNITS,
            );
        }

        return $admin;
    }

    private function saleUnit(string $code): SaleUnit
    {
        $saleUnit = (new SaleUnit())
            ->setCode($code)
            ->setActive(true)
            ->setPosition(SaleUnitQuery::create()->count() + 1)
            ->setLocale('en_US')
            ->setTitle($code.' title');
        $saleUnit->save($this->getPropelConnection());

        return $saleUnit;
    }

    private function actAs(\Thelia\Model\Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    /**
     * The token the list renders in its forms, as the browser submits it.
     */
    private function tokenOfTheList(): string
    {
        $crawler = $this->client->request('GET', '/admin/orders');
        $token = $crawler->filter('[data-testid="order-picking-slip-filter-form"] input[name="_token"]')->first();

        self::assertGreaterThan(0, $token->count(), 'The list renders a CSRF token.');

        return (string) $token->attr('value');
    }

    private function pageCount(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);
    }
}
