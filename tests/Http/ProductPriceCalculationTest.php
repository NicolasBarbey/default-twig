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
use Thelia\Domain\Taxation\TaxEngine\TaxType\FeatureFixAmountTaxType;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Product;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleCountry;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

final class ProductPriceCalculationTest extends WebIntegrationTestCase
{
    private const URL = '/admin/product/calculate-price';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private TaxRule $taxRule;

    private Product $product;

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

        $lang = LangQuery::create()->findOneByLocale('en_US', $this->getPropelConnection());
        self::assertNotNull($lang);
        $feature = $this->factory->feature(['title' => 'Eco-tax']);
        $tax = $this->factory->tax([
            'type' => FeatureFixAmountTaxType::class,
            'requirements' => ['feature' => $feature->getId(), 'lang' => $lang->getId()],
            'title' => 'Eco-tax',
        ]);
        // A non-empty override array forces a rule of its own instead of reusing the seeded one.
        $this->taxRule = $this->factory->taxRule(['isDefault' => false]);
        (new TaxRuleCountry())
            ->setTaxRuleId($this->taxRule->getId())
            ->setCountryId($this->defaultCountry()->getId())
            ->setTaxId($tax->getId())
            ->setPosition(1)
            ->save($this->getPropelConnection());

        $this->product = $this->factory->product($this->factory->category(), $this->taxRule, $this->defaultCurrency());
        $this->factory->featureProduct($this->product, $this->factory->featureAv($feature, ['title' => '2.5']));
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    public function testAPriceIsTaxedWithTheFeatureOfTheProductSent(): void
    {
        self::assertSame(12.5, $this->converted(10.0, 'untaxed_to_taxed', $this->product));
    }

    public function testAPriceWithTaxesIsUntaxedWithTheFeatureOfTheProductSent(): void
    {
        self::assertSame(10.0, $this->converted(12.5, 'taxed_to_untaxed', $this->product));
    }

    public function testWithoutAProductAFeatureTaxCountsForNothing(): void
    {
        // The creation form of a product, which has no product yet.
        self::assertSame(10.0, $this->converted(10.0, 'untaxed_to_taxed', null));
    }

    public function testAnUnknownTaxRuleIsRefusedRatherThanAnsweredWithThePriceSent(): void
    {
        $this->client->request('GET', self::URL, ['price' => 10, 'tax_rule_id' => 999999999, 'action' => 'untaxed_to_taxed']);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayNotHasKey('result', $this->answer());
    }

    public function testThePriceFormOfAProductSendsItsProduct(): void
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$this->product->getId());
        $form = $crawler->filter('form[data-testid="default-pse-form"]');

        self::assertGreaterThan(0, $form->count(), 'A product without combinations shows its price form.');
        self::assertSame((string) $this->product->getId(), $form->attr('data-bo-pse-price-with-tax-product-id-value'));
    }

    private function converted(float $price, string $action, ?Product $product): float
    {
        $query = ['price' => $price, 'tax_rule_id' => $this->taxRule->getId(), 'action' => $action];
        if ($product !== null) {
            $query['product_id'] = $product->getId();
        }

        $this->client->request('GET', self::URL, $query);
        self::assertResponseIsSuccessful();

        return (float) $this->answer()['result'];
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);

        return $answer;
    }

    private function defaultCountry(): Country
    {
        $country = CountryQuery::create()->findOneByByDefault(1, $this->getPropelConnection());
        self::assertNotNull($country, 'The test shop has no default country.');

        return $country;
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }
}
