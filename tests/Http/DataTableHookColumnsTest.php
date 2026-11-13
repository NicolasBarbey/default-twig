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
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The columns a module adds to a list through the <id>.table-header and <id>.table-row hooks come
 * before the actions, as the Smarty lists had them: the actions stay the last column, the one that
 * sticks to the end of the screen when the list scrolls sideways.
 */
final class DataTableHookColumnsTest extends WebIntegrationTestCase
{
    private const HEADER_HOOK_EVENT = 'hook.'.TemplateDefinition::BACK_OFFICE.'.orders.table-header';

    private const ROW_HOOK_EVENT = 'hook.'.TemplateDefinition::BACK_OFFICE.'.orders.table-row';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    /** @var list<array{string, callable}> */
    private array $listeners = [];

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
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        foreach ($this->listeners as [$eventName, $listener]) {
            $dispatcher->removeListener($eventName, $listener);
        }
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testAModuleColumnComesRightBeforeTheActions(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()), ['statusCode' => OrderStatus::CODE_PAID]);

        $this->listen(self::HEADER_HOOK_EVENT, static function (HookRenderEvent $event): void {
            $event->add('<th data-testid="module-column-header">Label</th>');
        });
        $this->listen(self::ROW_HOOK_EVENT, static function (HookRenderEvent $event): void {
            $event->add(\sprintf('<td data-testid="module-cell-%d">Printed</td>', (int) $event->getArgument('row_id')));
        });

        $crawler = $this->client->request('GET', '/admin/orders?q='.urlencode((string) $order->getRef()));
        self::assertResponseIsSuccessful();
        $table = $crawler->filter('[data-testid="datatable-orders-table"]');

        $header = $table->filter('thead > tr > th')->each(static fn (Crawler $cell): string => (string) ($cell->attr('data-testid') ?? $cell->attr('class')));
        self::assertSame('module-column-header', $header[\count($header) - 2], 'The header of the module comes right before the one of the actions.');
        self::assertStringContainsString('datatable-sticky-actions', $header[\count($header) - 1]);

        $row = $table->filter('[data-testid="datatable-orders-row"][data-row-id="'.$order->getId().'"]');
        $cells = $row->filter('td')->each(static fn (Crawler $cell): string => (string) ($cell->attr('data-testid') ?? ''));
        self::assertSame('module-cell-'.$order->getId(), $cells[\count($cells) - 2], 'The cell of the module comes right before the actions, on the row of its order.');
        self::assertSame('datatable-orders-cell-_actions', $cells[\count($cells) - 1]);
    }

    private function listen(string $eventName, callable $listener): void
    {
        $this->getService(EventDispatcherInterface::class)->addListener($eventName, $listener);
        $this->listeners[] = [$eventName, $listener];
    }
}
