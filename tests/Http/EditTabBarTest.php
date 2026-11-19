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

use BackOfficeDefaultTwigBundle\BackOfficeDefaultTwigBundle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The tab bar of an edit sheet keeps its tabs on one row (bo-tab-overflow moves the ones that
 * do not fit into a More menu) and offers the edit languages in a dropdown that only shows the
 * language being edited.
 */
final class EditTabBarTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
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
        $this->injector->clear();

        parent::tearDown();
    }

    public function testTheTabBarHandsTheTabsThatDoNotFitToItsMoreMenu(): void
    {
        $crawler = $this->client->request('GET', $this->productSheet());
        self::assertResponseIsSuccessful();

        $bar = $crawler->filter('.bo-tab-bar');
        self::assertCount(1, $bar);
        self::assertSame('bo-tab-overflow', $bar->attr('data-controller'));
        self::assertNotSame('', (string) $bar->attr('data-bo-tab-overflow-more-label-value'), 'The More menu gets a translated label.');
    }

    public function testTheEditLanguagesSitInADropdownThatShowsTheEditedOne(): void
    {
        $languages = LangQuery::create()->filterByActive(true)->find();
        if ($languages->count() < 2) {
            self::markTestSkipped('The switcher only shows up when the shop has several active languages.');
        }

        $edited = $languages->getLast();
        $crawler = $this->client->request('GET', $this->productSheet().'&edit_language_id='.$edited->getId());
        self::assertResponseIsSuccessful();

        $switcher = $crawler->filter('.bo-tab-bar [data-testid="bo-language-switcher"]');
        self::assertCount(1, $switcher);
        self::assertSame(strtoupper($edited->getCode()), trim($switcher->filter('[data-testid="bo-language-switcher-toggle"]')->text()));
        self::assertSame('dropdown', $switcher->filter('[data-testid="bo-language-switcher-toggle"]')->attr('data-bs-toggle'));

        $entries = $switcher->filter('.dropdown-menu .dropdown-item');
        self::assertCount($languages->count(), $entries, 'One entry per active language.');
        self::assertSame(['true'], $switcher->filter('.dropdown-item.active')->each(static fn ($entry): ?string => $entry->attr('aria-current')));
        self::assertStringContainsString('edit_language_id='.$edited->getId(), (string) $switcher->filter('.dropdown-item.active')->attr('href'));
    }

    private function productSheet(): string
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency ?? $this->factory->currency());

        return '/admin/products/update?product_id='.$product->getId();
    }
}
