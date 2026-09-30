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
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Changing the status is the most frequent action on an order: the order sheet
 * offers it in its header, reachable without opening a tab.
 */
final class OrderDetailStatusFormTest extends WebIntegrationTestCase
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

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
        $this->injector->clear();

        parent::tearDown();
    }

    public function testTheStatusFormSitsInTheHeaderOutsideTheTabs(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="order-status-form"]'));
        self::assertCount(
            0,
            $crawler->filter('.tab-content [data-testid="order-status-form"]'),
            'The status form must not hide behind a tab.',
        );
        self::assertCount(1, $crawler->filter('[data-testid="order-status-form"] [data-testid="order-status-select"]'));
    }
}
