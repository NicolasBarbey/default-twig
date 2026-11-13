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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The order list is read for its total: it comes right after the customer, with the number
 * of lines underneath, and the actions of a row sit behind a single button at its end.
 */
final class OrderListLayoutTest extends WebIntegrationTestCase
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
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheColumnsComeByWhatTheListIsReadFor(): void
    {
        $crawler = $this->client->request('GET', '/admin/orders');
        self::assertResponseIsSuccessful();

        // The selection box and the toggle of the row details carry no title to read.
        $headers = array_values(array_filter(
            $crawler->filter('[data-testid="datatable-orders-table"] thead th')->each(static fn ($th): string => trim($th->text())),
            static fn (string $text): bool => $text !== '' && !str_starts_with($text, 'Select') && $text !== 'Details',
        ));

        self::assertSame(['Order', 'Customer', 'Total', 'Status', 'Payment / Delivery', 'Actions'], $headers);
        $actions = $crawler->filter('[data-testid="datatable-orders-table"] thead th.datatable-sticky-actions');
        self::assertSame('Actions', trim($actions->filter('.visually-hidden')->text('')), 'The title of the actions is read out, out of sight: the single button of the column speaks for itself.');
    }

    public function testTheTotalOffersNoSortAndALinkAskingForItFallsBackOnTheDefaultOne(): void
    {
        $crawler = $this->client->request('GET', '/admin/orders');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="datatable-orders-sort-amount"]'), 'The orders were sorted by id under the title of the total.');

        $crawler = $this->client->request('GET', '/admin/orders?order=amount');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="datatable-orders-sort-created_at"] .bi-sort-down'), 'The newest orders first, as without a sort.');
    }

    public function testTheTotalShowsTheNumberOfLinesUnderneathAndTheActionsSitBehindOneButton(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()), ['statusCode' => OrderStatus::CODE_PAID]);
        $this->factory->orderProduct($order);
        $this->factory->orderProduct($order);

        $crawler = $this->client->request('GET', '/admin/orders?q='.urlencode((string) $order->getRef()));
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-testid="datatable-orders-row"][data-row-id="'.$order->getId().'"]');
        self::assertCount(1, $row);

        $total = $row->filter('[data-testid="datatable-orders-cell-total_html"]');
        self::assertCount(1, $total->filter('.fw-semibold [dir="ltr"]'), 'The amount comes first, read left to right in every language.');
        self::assertSame('2 articles', trim($total->filter('.bo-order-items')->text()));

        $actions = $row->filter('[data-testid="datatable-orders-cell-_actions"]');
        self::assertCount(1, $actions->filter('.bo-row-menu > .dropdown-toggle[data-testid="datatable-action-overflow"]'), 'The button keeps its end corners, its menu following it in the group.');
        self::assertCount(0, $actions->filter('.btn.bo-action'), 'No action is drawn as a button of its own.');
        self::assertCount(1, $actions->filter('.dropdown-menu [data-testid="datatable-action-view"]'), 'The menu keeps the entry to the order, the reference opening it too.');
        self::assertCount(1, $actions->filter('.dropdown-menu [data-testid="datatable-action-invoice"]'));
        self::assertCount(1, $actions->filter('.dropdown-menu [data-testid="datatable-action-delivery-slip"]'));
        self::assertCount(1, $actions->filter('.dropdown-menu [data-testid="datatable-action-cancel"]'));
    }
}
