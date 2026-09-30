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
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductPriceTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The two forms of the combinations tab of a product sheet, over HTTP: the
 * table of combinations and the default pricing of a product without any.
 * Both only save through a POST that carries the token of the form, and a
 * price the form did not send keeps its stored value.
 */
final class ProductCombinationUpdateTest extends WebIntegrationTestCase
{
    private const COMBINATIONS_URL = '/admin/product/combinations/update';
    private const DEFAULT_PRICE_URL = '/admin/product/default-price/update';

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

        // Built without createFixtureFactory(), which would push a synthetic
        // main request the SecurityContext then reads its session from.
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    public function testTheCombinationsCannotBeUpdatedThroughAGet(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('GET', self::COMBINATIONS_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [0],
            'price' => [0.01],
        ]);

        $this->assertNotSaved();
        $this->assertCombinationUntouched($pse);
    }

    public function testTheCombinationsCannotBeUpdatedWithoutTheFormToken(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $token = $this->tokenOf($product, 'combinations-form');

        $this->client->request('POST', self::COMBINATIONS_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [0],
            'price' => [0.01],
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::COMBINATIONS_URL.'?_token='.$token, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [0],
            'price' => [0.01],
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The token is read from the form body only.');

        $this->assertCombinationUntouched($pse);
    }

    public function testTheDefaultPriceCannotBeUpdatedThroughAGet(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->client->request('GET', self::DEFAULT_PRICE_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'quantity' => 43,
            'price' => 0.01,
        ]);

        $this->assertNotSaved();
        $this->assertCombinationUntouched($pse);
    }

    public function testTheDefaultPriceCannotBeUpdatedWithoutTheFormToken(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();
        $token = $this->tokenOf($product, 'default-pse-form');

        $this->client->request('POST', self::DEFAULT_PRICE_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'quantity' => 43,
            'price' => 0.01,
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::DEFAULT_PRICE_URL.'?_token='.$token, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'quantity' => 43,
            'price' => 0.01,
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The token is read from the form body only.');

        $this->assertCombinationUntouched($pse);
    }

    private function loginFullAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithoutCombination(): array
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            ['basePrice' => 25.0, 'baseQuantity' => 7],
        );
        $pse = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($pse);

        $price = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($price);
        $price->setPromoPrice('20.000000')->save($this->getPropelConnection());

        return [$product, $pse];
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(): array
    {
        [$product, $pse] = $this->productWithoutCombination();

        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Large']));

        return [$product, $pse];
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }

    /**
     * The token the given form of the combinations tab renders in its body, so
     * the POST is submitted the way the browser submits it.
     */
    private function tokenOf(Product $product, string $formTestId): string
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $token = $crawler->filter(\sprintf('form[data-testid="%s"] input[name="_token"]', $formTestId))->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('The "%s" form renders no token field.', $formTestId));

        return (string) $token->attr('value');
    }

    /**
     * A GET never reaches the save of the Twig back-office. When the legacy
     * back-office is installed next to it, its route of the same path answers
     * instead, and it only saves a POSTed form: the status then depends on how
     * that page renders, so the check is that nothing was saved.
     */
    private function assertNotSaved(): void
    {
        self::assertFalse(
            $this->client->getResponse()->isRedirection(),
            'A GET must not be answered with the redirection that follows a save.',
        );
    }

    private function assertCombinationUntouched(ProductSaleElements $pse): void
    {
        self::assertSame(7.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The stock must not change.');
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice(), 'The price must not change.');
    }

    private function freshSaleElement(ProductSaleElements $pse): ProductSaleElements
    {
        ProductSaleElementsTableMap::clearInstancePool();
        $fresh = ProductSaleElementsQuery::create()->findPk($pse->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function storedPrice(ProductSaleElements $pse): ProductPrice
    {
        ProductPriceTableMap::clearInstancePool();
        $price = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($price);

        return $price;
    }
}
