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

use BackOfficeDefaultTwigBundle\Tests\Support\Kernel\AdminRouteConditionKernel;
use BackOfficeDefaultTwigBundle\Tests\Support\Routing\AdminRouteConditionProbe;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Before the router answers a GET on the back-office, the theme matches it
 * once more to refuse the actions that only answer a POST. That match must
 * evaluate a route condition calling service() or env() the way the router
 * does: without the functions of the routing context, the condition crashed
 * and the page answered a 500.
 */
final class AdminRouteConditionTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected static function getKernelClass(): string
    {
        return AdminRouteConditionKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its listeners are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->dispatcher()->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->dispatcher()->removeSubscriber($this->injector);
        $this->injector->clear();

        parent::tearDown();
    }

    public function testARouteConditionCallingAServiceLetsTheRequestThrough(): void
    {
        $this->client->request('GET', AdminRouteConditionKernel::ROUTE_PATH.'?allowed=1');

        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode(), (string) mb_substr((string) $response->getContent(), 0, 2000));
        self::assertSame(AdminRouteConditionProbe::BODY, $response->getContent());
    }

    public function testARouteConditionCallingAServiceTurnsTheRequestAway(): void
    {
        $this->client->request('GET', AdminRouteConditionKernel::ROUTE_PATH);

        $response = $this->client->getResponse();

        self::assertSame(404, $response->getStatusCode(), (string) mb_substr((string) $response->getContent(), 0, 2000));
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
