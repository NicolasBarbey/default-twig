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
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

final class ProductCombinationRowActionsTest extends WebIntegrationTestCase
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

        // Not createFixtureFactory(): it pushes a synthetic main request the SecurityContext reads its session from.
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

    public function testTheActionsOfACombinationSitInOneMenuWithTheDeletionLast(): void
    {
        [$product, $combination] = $this->productWithACombination();

        $menu = $this->menuOf($product, $combination);

        $toggle = $this->tab($product)->filter(\sprintf('[data-testid="combinations-actions-%d"]', $combination->getId()));
        self::assertSame('dropdown', $toggle->attr('data-bs-toggle'), 'The row opens its actions from one button.');
        self::assertStringContainsString('Large', (string) $toggle->attr('aria-label'), 'The button names the combination it acts on, for a screen reader.');

        $entries = $menu->filter('li > .dropdown-item')->each(static fn (Crawler $item): string => (string) $item->attr('data-testid'));
        $id = $combination->getId();
        self::assertSame([
            'combinations-assoc-image-'.$id,
            'combinations-assoc-document-'.$id,
            'combinations-assoc-video-'.$id,
            'combinations-assoc-virtual-'.$id,
            'combinations-delete-'.$id,
        ], $entries);

        // The dialogs read the combination from the entry that opened them.
        $menu->filter('li > .dropdown-item')->each(static function (Crawler $item) use ($id): void {
            self::assertSame((string) $id, $item->attr('data-pse-id'));
        });
        self::assertCount(
            1,
            $menu->filterXPath(\sprintf('//li[hr[contains(@class, "dropdown-divider")]]/following-sibling::li[1]/*[@data-testid="combinations-delete-%d"]', $id)),
            'A separator stands right before the deletion.',
        );
    }

    private function menuOf(Product $product, ProductSaleElements $combination): Crawler
    {
        $menu = $this->tab($product)->filter(\sprintf('ul.dropdown-menu[data-testid="combinations-actions-menu-%d"]', $combination->getId()));
        self::assertCount(1, $menu, 'The row of the combination has a menu of actions.');

        return $menu;
    }

    private function tab(Product $product): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(): array
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->defaultCurrency(), ['basePrice' => 25.0]);
        $combination = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($combination);

        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($combination, $this->factory->attributeAv($attribute, ['title' => 'Large']));

        return [$product, $combination];
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }
}
