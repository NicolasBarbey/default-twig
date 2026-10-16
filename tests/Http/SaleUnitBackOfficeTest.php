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

use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderSaleUnits;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\SaleUnit\SaleUnitActivityChecker;
use Thelia\Model\AdminLogQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitProductPriceQuery;
use Thelia\Model\SaleUnitProductSaleElementsQuery;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The sale units in the back office: their own screen, their prices and stocks on the
 * combinations tab of a product, and their place on the order list and sheet. A shop
 * without any sale unit sees none of it.
 */
final class SaleUnitBackOfficeTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

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

        // The suite may run on a shop that already declares sale units.
        SaleUnitQuery::create()->find($this->getPropelConnection())->delete($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testASingleShopShowsNothingOfTheSaleUnits(): void
    {
        [$product] = $this->productWithACombination();

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="product-combinations-tab"]'));
        self::assertCount(0, $crawler->filter('[data-testid="sale-unit-values-section"]'));

        $crawler = $this->client->request('GET', '/admin/orders');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="order-filter-sale-unit"]'));
        self::assertStringNotContainsString('Sale unit', $crawler->filter('table thead')->text(''));
    }

    public function testTheMerchantCreatesAndEditsASaleUnit(): void
    {
        $this->client->request('POST', '/admin/configuration/sale-units/create', ['thelia_sale_unit_creation' => [
            'code' => 'north',
            'url' => 'https://North.Example.test/shop/',
            'title' => 'North store',
            'description' => '',
            'active' => '1',
            'locale' => 'en_US',
            '_token' => $this->formToken('/admin/configuration/sale-units', 'thelia_sale_unit_creation'),
        ]]);

        $saleUnit = SaleUnitQuery::create()->findOneByCode('north');
        self::assertNotNull($saleUnit, 'The sale unit is created.');
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/configuration/sale-units/update/'.$saleUnit->getId()));
        self::assertSame('north.example.test', $saleUnit->getUrl(), 'The host the domain resolver compares is kept, nothing else.');
        self::assertTrue((bool) $saleUnit->getActive());
        self::assertSame('North store', $saleUnit->setLocale('en_US')->getTitle());

        $editUrl = '/admin/configuration/sale-units/update/'.$saleUnit->getId();
        $this->client->request('POST', '/admin/configuration/sale-units/save/'.$saleUnit->getId(), ['thelia_sale_unit_modification' => [
            'id' => (string) $saleUnit->getId(),
            'code' => 'north-1',
            'url' => '',
            'title' => 'Magasin nord',
            'description' => 'Next to the station',
            'locale' => 'fr_FR',
            '_token' => $this->formToken($editUrl, 'thelia_sale_unit_modification'),
        ]]);

        $saleUnit = SaleUnitQuery::create()->findPk($saleUnit->getId());
        self::assertNotNull($saleUnit);
        self::assertSame('north-1', $saleUnit->getCode());
        self::assertNull($saleUnit->getUrl(), 'An empty address is no address.');
        self::assertFalse((bool) $saleUnit->getActive(), 'An unticked box turns the unit off.');
        self::assertSame('Magasin nord', $saleUnit->setLocale('fr_FR')->getTitle());
        self::assertSame('North store', $saleUnit->setLocale('en_US')->getTitle(), 'The other language keeps its title.');
    }

    public function testACodeOrAnAddressAlreadyTakenIsRefused(): void
    {
        $this->saleUnit('north', 'north.example.test');

        $this->client->request('POST', '/admin/configuration/sale-units/create', ['thelia_sale_unit_creation' => [
            'code' => 'north',
            'title' => 'Twin',
            'locale' => 'en_US',
            '_token' => $this->formToken('/admin/configuration/sale-units', 'thelia_sale_unit_creation'),
        ]]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already uses the code north', $crawler->filter('.alert-danger')->text(''));

        $this->client->request('POST', '/admin/configuration/sale-units/create', ['thelia_sale_unit_creation' => [
            'code' => 'south',
            'url' => 'NORTH.example.test',
            'title' => 'South',
            'locale' => 'en_US',
            '_token' => $this->formToken('/admin/configuration/sale-units', 'thelia_sale_unit_creation'),
        ]]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already answers on north.example.test', $crawler->filter('.alert-danger')->text(''));

        self::assertSame(1, SaleUnitQuery::create()->count());
    }

    public function testTheMerchantTurnsOffAndReordersTheSaleUnits(): void
    {
        $north = $this->saleUnit('north');
        $south = $this->saleUnit('south');

        $this->client->request('POST', '/admin/configuration/sale-units/toggle-active?sale_unit_id='.$north->getId(), ['_token' => $this->sessionToken()]);
        self::assertFalse((bool) SaleUnitQuery::create()->findPk($north->getId())?->getActive());

        $this->client->request('POST', '/admin/configuration/sale-units/update-position?sale_unit_id='.$south->getId().'&position=1', ['_token' => $this->sessionToken()]);
        self::assertSame(1, SaleUnitQuery::create()->findPk($south->getId())?->getPosition());
        self::assertSame(2, SaleUnitQuery::create()->findPk($north->getId())?->getPosition());
    }

    public function testASaleUnitOrdersWerePlacedOnCannotBeDeleted(): void
    {
        $kept = $this->saleUnit('kept');
        $order = $this->factory->order();
        $order->setSaleUnitId($kept->getId())->save($this->getPropelConnection());
        $gone = $this->saleUnit('gone');

        $this->client->request('POST', '/admin/configuration/sale-units/delete', ['sale_unit_id' => $kept->getId(), '_token' => $this->sessionToken()]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('1 order(s) were placed on it', $crawler->filter('.alert-danger')->text(''));
        self::assertNotNull(SaleUnitQuery::create()->findPk($kept->getId()));

        $this->client->request('POST', '/admin/configuration/sale-units/delete', ['sale_unit_id' => $gone->getId(), '_token' => $this->sessionToken()]);
        self::assertNull(SaleUnitQuery::create()->findPk($gone->getId()));
    }

    public function testAnOwnPriceAndStockReplaceTheNationalOnesUntilTheyAreEmptied(): void
    {
        $saleUnit = $this->saleUnit('north');
        [$product, $pse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="sale-unit-values-section"]'));
        self::assertSame('25', $crawler->filter('[data-testid="sale-unit-values-price-'.$pse->getId().'"]')->attr('placeholder'), 'The national price stands in for an empty field.');
        self::assertSame('', (string) $crawler->filter('[data-testid="sale-unit-values-price-'.$pse->getId().'"]')->attr('value'));

        $this->saveValues($product, $saleUnit, $currency, $pse, price: '19,5', promoPrice: '', quantity: '3');

        $price = SaleUnitProductPriceQuery::create()->filterBySaleUnitId($saleUnit->getId())->findOneByProductSaleElementsId($pse->getId());
        self::assertNotNull($price);
        self::assertSame(19.5, (float) $price->getPrice());
        self::assertSame(19.5, (float) $price->getPromoPrice(), 'A promo price left empty sells at the price, never for free.');
        self::assertSame(3.0, (float) SaleUnitProductSaleElementsQuery::create()->filterBySaleUnitId($saleUnit->getId())->findOneByProductSaleElementsId($pse->getId())?->getQuantity());
        self::assertSame(25.0, (float) ProductPriceQuery::create()->filterByCurrencyId($currency->getId())->findOneByProductSaleElementsId($pse->getId())?->getPrice(), 'The national price is untouched.');
        self::assertSame(7.0, (float) ProductSaleElementsQuery::create()->findPk($pse->getId())?->getQuantity(), 'The national stock is untouched.');

        $this->saveValues($product, $saleUnit, $currency, $pse, price: '', promoPrice: '', quantity: '');

        self::assertSame(0, SaleUnitProductPriceQuery::create()->filterBySaleUnitId($saleUnit->getId())->count(), 'An emptied price gives the combination back to the national price.');
        self::assertSame(0, SaleUnitProductSaleElementsQuery::create()->filterBySaleUnitId($saleUnit->getId())->count(), 'An emptied stock gives it back to the national stock.');
    }

    /**
     * A promo price left empty is stored equal to the price. Shown back as that value, it
     * would stay behind when the merchant changes the price, and a combination on sale
     * would keep selling at the old price.
     */
    public function testAPromoPriceLeftEmptyFollowsALaterChangeOfThePrice(): void
    {
        $saleUnit = $this->saleUnit('north');
        [$product, $pse] = $this->productWithACombination();
        $currency = $this->defaultCurrency();

        $this->saveValues($product, $saleUnit, $currency, $pse, price: '19.5', promoPrice: '', quantity: '');

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $promoField = $crawler->filter('[data-testid="sale-unit-values-promo_price-'.$pse->getId().'"]');
        self::assertSame('', (string) $promoField->attr('value'), 'A promo price equal to the price is shown empty.');
        self::assertSame('19.5', $promoField->attr('placeholder'));

        // The merchant only changes the price and sends the promo field as the screen showed it.
        $this->saveValues($product, $saleUnit, $currency, $pse, price: '22', promoPrice: (string) $promoField->attr('value'), quantity: '');

        $price = SaleUnitProductPriceQuery::create()->filterBySaleUnitId($saleUnit->getId())->findOneByProductSaleElementsId($pse->getId());
        self::assertSame(22.0, (float) $price?->getPromoPrice());
    }

    public function testAnotherCurrencyShowsTheUnitPriceConvertedFromTheDefaultCurrency(): void
    {
        $saleUnit = $this->saleUnit('north');
        [$product, $pse] = $this->productWithACombination();
        $default = $this->defaultCurrency();
        $other = $this->factory->currency(['code' => 'XTS', 'symbol' => 'X', 'rate' => (float) $default->getRate() * 2]);

        $this->saveValues($product, $saleUnit, $default, $pse, price: '10', promoPrice: '8', quantity: '');

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId().'&currency_id='.$other->getId());
        $price = $crawler->filter('[data-testid="sale-unit-values-price-'.$pse->getId().'"]');
        self::assertSame('', (string) $price->attr('value'));
        self::assertSame('20', $price->attr('placeholder'), 'The unit price of the default currency, converted, applies.');
        self::assertStringContainsString('Converted from '.$default->getCode(), $crawler->filter('[data-testid="sale-unit-values-price-source-'.$pse->getId().'"]')->text());
        self::assertSame('16', $crawler->filter('[data-testid="sale-unit-values-promo_price-'.$pse->getId().'"]')->attr('placeholder'));
    }

    public function testTheSectionNeedsTheRightOnSaleUnits(): void
    {
        $this->saleUnit('north');
        [$product] = $this->productWithACombination();

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="product-combinations-tab"]'));
        self::assertCount(0, $crawler->filter('[data-testid="sale-unit-values-section"]'));

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW], AdminResources::SALE_UNIT => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="sale-unit-values-section"]'));
        self::assertCount(0, $crawler->filter('[data-testid="sale-unit-values-submit"]'), 'Saving needs both update rights.');

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE], AdminResources::SALE_UNIT => [AccessManager::VIEW, AccessManager::UPDATE]]));
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="sale-unit-values-submit"]'));
    }

    public function testAnUnknownCurrencyIsRefusedWithoutAnError(): void
    {
        $saleUnit = $this->saleUnit('north');
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('POST', '/admin/product/sale-unit-values/save', [
            '_token' => $this->sessionToken(),
            'product_id' => $product->getId(),
            'sale_unit_id' => $saleUnit->getId(),
            'currency_id' => 999999,
            'price' => [$pse->getId() => '10'],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertSame(0, SaleUnitProductPriceQuery::create()->filterBySaleUnitId($saleUnit->getId())->count());
    }

    public function testNothingIsLoggedWhenNothingWasWritten(): void
    {
        $saleUnit = $this->saleUnit('north');
        [$product, $pse] = $this->productWithACombination();
        $logged = AdminLogQuery::create()->filterByResource(AdminResources::SALE_UNIT)->count();

        $this->saveValues($product, $saleUnit, $this->defaultCurrency(), $pse, price: '', promoPrice: '5', quantity: '');
        self::assertSame($logged, AdminLogQuery::create()->filterByResource(AdminResources::SALE_UNIT)->count());

        $this->saveValues($product, $saleUnit, $this->defaultCurrency(), $pse, price: '12', promoPrice: '', quantity: '');
        self::assertSame($logged + 1, AdminLogQuery::create()->filterByResource(AdminResources::SALE_UNIT)->count());
    }

    /**
     * The order list and sheet of a single shop ask the database nothing about sale units:
     * whether the shop sells from them is read from the configuration pool.
     */
    public function testTheOrderScreensOfASingleShopReadNoSaleUnitTable(): void
    {
        $order = $this->factory->order();
        $this->client->request('GET', '/admin/orders');

        $statements = $this->recordSqlQueries(function () use ($order): void {
            // A later request is another process: only what the shared pool kept is remembered.
            $this->getService(SaleUnitActivityChecker::class)->reset();
            $this->getService(OrderSaleUnits::class)->reset();

            $this->client->request('GET', '/admin/orders');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->client->request('GET', '/admin/order/update/'.$order->getId());
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        });

        self::assertNotSame([], $statements, 'The requests are recorded.');
        self::assertSame([], array_values(array_filter(
            $statements,
            static fn (string $statement): bool => 1 === preg_match('/FROM `sale_unit(_\w+)?`/', $statement),
        )));
    }

    public function testTheSaleUnitsAreReadWithTheirTitlesInOneQuery(): void
    {
        $this->saleUnit('north');
        $this->saleUnit('south');
        $this->saleUnit('west');
        $repository = $this->getService(SaleUnitRepository::class);

        self::assertSame(1, QueryCounter::count(static fn () => $repository->findOptions('en_US')));
        self::assertSame(1, QueryCounter::count(static fn () => $repository->findOptions('fr_FR')), 'A missing translation costs no query of its own.');

        $codes = [];
        foreach ($repository->findSorted('fr_FR', 'title', 'asc') as $saleUnit) {
            $codes[] = $saleUnit->getCode();
        }
        self::assertSame(['north', 'south', 'west'], $codes, 'Sorted by a title none of them has in this language, every unit is still listed.');
    }

    public function testAPromoPriceWithoutAPriceIsRefused(): void
    {
        $saleUnit = $this->saleUnit('north');
        [$product, $pse] = $this->productWithACombination();

        $this->saveValues($product, $saleUnit, $this->defaultCurrency(), $pse, price: '', promoPrice: '5', quantity: '');

        self::assertSame(0, SaleUnitProductPriceQuery::create()->filterBySaleUnitId($saleUnit->getId())->count());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('a promo price needs a price', $crawler->filter('.alert-warning')->text(''));
    }

    public function testTheOrderListFiltersOnASaleUnitAndTheSheetNamesIt(): void
    {
        $north = $this->saleUnit('north');
        $south = $this->saleUnit('south');
        $northOrder = $this->factory->order();
        $northOrder->setSaleUnitId($north->getId())->save($this->getPropelConnection());
        $southOrder = $this->factory->order();
        $southOrder->setSaleUnitId($south->getId())->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/orders?sale_unit_ids[]='.$north->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="order-filter-sale-unit"]'));
        $refs = $crawler->filter('[data-testid="bo-order-ref-link"]')->each(static fn ($link): string => trim($link->text()));
        self::assertContains($northOrder->getRef(), $refs);
        self::assertNotContains($southOrder->getRef(), $refs, 'The filter only keeps the orders of its sale unit.');

        $crawler = $this->client->request('GET', '/admin/order/update/'.$northOrder->getId());
        self::assertStringContainsString('north title', $crawler->filter('[data-testid="order-sale-unit"]')->text(''));
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function restrictedAdmin(array $grants): \Thelia\Model\Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        return $admin;
    }

    private function saleUnit(string $code, ?string $url = null): SaleUnit
    {
        $saleUnit = (new SaleUnit())
            ->setCode($code)
            ->setUrl($url)
            ->setActive(true)
            ->setPosition(SaleUnitQuery::create()->count() + 1)
            ->setLocale('en_US')
            ->setTitle($code.' title');
        $saleUnit->save($this->getPropelConnection());

        return $saleUnit;
    }

    private function saveValues(Product $product, SaleUnit $saleUnit, Currency $currency, ProductSaleElements $pse, string $price, string $promoPrice, string $quantity): void
    {
        $this->client->request('POST', '/admin/product/sale-unit-values/save', [
            '_token' => $this->sessionToken(),
            'product_id' => $product->getId(),
            'sale_unit_id' => $saleUnit->getId(),
            'currency_id' => $currency->getId(),
            'price' => [$pse->getId() => $price],
            'promo_price' => [$pse->getId() => $promoPrice],
            'quantity' => [$pse->getId() => $quantity],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirection());
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(): array
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            ['basePrice' => 25.0, 'baseQuantity' => 7],
        );
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($pse);

        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Large']));

        return [$product, $pse];
    }

    private function defaultCurrency(): Currency
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        self::assertNotNull($currency);

        return $currency;
    }

    private function formToken(string $page, string $formName): string
    {
        $crawler = $this->client->request('GET', $page);
        $token = $crawler->filter(\sprintf('input[name="%s[_token]"]', $formName));
        self::assertGreaterThan(0, $token->count(), \sprintf('The page renders the %s form.', $formName));

        return (string) $token->attr('value');
    }

    private function sessionToken(): string
    {
        $html = (string) $this->client->request('GET', '/admin/configuration/sale-units')->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }
}
