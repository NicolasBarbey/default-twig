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
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeTemplate;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * A shop migrated from Thelia 2 holds every combination of a product at the same position: the tab
 * shows them in the order of their attribute values, and a move must follow what the tab shows.
 */
final class ProductCombinationPositionTest extends WebIntegrationTestCase
{
    private const POSITION_URL = '/admin/product/product-sale-element-position';
    private const BUILD_URL = '/admin/product/combination/build';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private Product $product;

    private Attribute $size;

    /** @var array<string, AttributeAv> */
    private array $values = [];

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

        $this->product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            ['basePrice' => 25.0],
        );

        $this->size = $this->factory->attribute(['title' => 'Size']);
        $this->values['XL'] = $this->givenValue('XL', 3);
        $this->values['S'] = $this->givenValue('S', 1);
        $this->values['M'] = $this->givenValue('M', 2);

        $template = $this->factory->template();
        $entry = new AttributeTemplate();
        $entry->setTemplateId($template->getId())->setAttributeId($this->size->getId())->setPosition(1)->save($this->getPropelConnection());
        $this->product->setTemplateId($template->getId())->save($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    public function testTiedCombinationsAreNumberedInTheOrderShownBeforeTheMove(): void
    {
        $this->givenTiedCombinations('XL', 'S', 'M');
        self::assertSame(['S', 'M', 'XL'], $this->shownOrder());

        // Numbered by id instead (XL, S, M), the move would give M, XL, S.
        $this->move('M', 1);

        self::assertResponseIsSuccessful();
        self::assertSame(['position' => 1], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(['M' => 1, 'S' => 2, 'XL' => 3], $this->storedPositions());
    }

    public function testAMoveTheCoreDidNotMakeIsReportedAsNotSaved(): void
    {
        $this->givenTiedCombinations('XL', 'S', 'M');
        // The core's move gives up in silence on a failure: here it does not run at all.
        $this->getService(EventDispatcherInterface::class)->addListener(
            TheliaEvents::PRODUCT_PRODUCT_SALE_ELEMENT_UPDATE_POSITION,
            static fn (UpdatePositionEvent $event) => $event->stopPropagation(),
            1024,
        );

        $this->move('M', 1);

        self::assertResponseStatusCodeSame(422);
    }

    public function testACombinationMovesDownAmongTiedCombinations(): void
    {
        $this->givenTiedCombinations('XL', 'S', 'M');

        $this->move('S', 2);

        self::assertResponseIsSuccessful();
        self::assertSame(['M' => 1, 'S' => 2, 'XL' => 3], $this->storedPositions());
    }

    public function testAPositionPastTheLastCombinationIsRefusedAndNumbersNothing(): void
    {
        $this->givenTiedCombinations('XL', 'S', 'M');

        $this->move('XL', 4);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['XL' => 1, 'S' => 1, 'M' => 1], $this->storedPositions());
    }

    public function testAnUnknownCombinationIsRefused(): void
    {
        $this->givenTiedCombinations('XL', 'S', 'M');
        $token = $this->sortToken();

        $this->client->request('POST', self::POSITION_URL.'?product_sale_element_id=999999999&position=1', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheMoveNeedsTheTokenInTheBody(): void
    {
        $combinations = $this->givenTiedCombinations('XL', 'S', 'M');
        $token = $this->sortToken();
        $url = self::POSITION_URL.'?product_sale_element_id='.$combinations['XL']->getId().'&position=1';

        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $url.'&_token='.$token);
        self::assertResponseStatusCodeSame(403, 'The token is read from the body only.');

        self::assertSame(['XL' => 1, 'S' => 1, 'M' => 1], $this->storedPositions());
    }

    public function testTheGeneratorCreatesTheCombinationsInTheOrderOfTheirValues(): void
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$this->product->getId());
        $form = $crawler->filter('form[data-testid="combination-builder-form"]');
        $offered = $form->filter(\sprintf('fieldset[data-testid="combination-builder-attr-%d"] input[name="attribute_av[]"]', $this->size->getId()))
            ->each(static fn ($input): string => (string) $input->attr('value'));

        self::assertSame(array_map(fn (string $title): string => $this->size->getId().':'.$this->values[$title]->getId(), ['S', 'M', 'XL']), $offered);

        $this->client->request('POST', self::BUILD_URL, [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'product_id' => $this->product->getId(),
            'attribute_av' => $offered,
            'price' => 10,
            // The form requires at least 1: the core's generator fails on a stock of 0.
            'quantity' => 1,
        ]);

        self::assertResponseRedirects();
        self::assertSame(['S' => 1, 'M' => 2, 'XL' => 3], $this->storedPositions());
    }

    private function givenValue(string $title, int $position): AttributeAv
    {
        $value = $this->factory->attributeAv($this->size, ['title' => $title]);
        $value->setPosition($position)->save($this->getPropelConnection());

        return $value;
    }

    /**
     * All at position 1, as after a Thelia 2 migration; the product's default combination carries the first.
     *
     * @return array<string, ProductSaleElements>
     */
    private function givenTiedCombinations(string ...$titles): array
    {
        $combinations = [];
        foreach ($titles as $index => $title) {
            $combination = $index === 0
                ? ProductSaleElementsQuery::create()->filterByProductId($this->product->getId())->findOne($this->getPropelConnection())
                : $this->factory->productSaleElement($this->product);
            self::assertNotNull($combination);
            $this->factory->attributeCombination($combination, $this->values[$title]);
            $combinations[$title] = $combination;
        }

        ProductSaleElementsQuery::create()
            ->filterByProductId($this->product->getId())
            ->update(['Position' => 1], $this->getPropelConnection());

        return $combinations;
    }

    private function move(string $title, int $position): void
    {
        $token = $this->sortToken();
        $combination = $this->combinationOf($title);

        $this->client->request(
            'POST',
            self::POSITION_URL.'?product_sale_element_id='.$combination->getId().'&position='.$position,
            ['_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    private function sortToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$this->product->getId());
        $sortable = $crawler->filter('tbody[data-controller="bo-sortable"]');
        self::assertGreaterThan(0, $sortable->count(), 'The combinations table renders no sortable body.');
        // combinationUpdate() reads these boxes by row index: a row moved without reload takes the index of its new place.
        self::assertSame(['onsale', 'isnew'], json_decode((string) $sortable->attr('data-bo-sortable-reindexed-fields-value'), true));

        return (string) $sortable->attr('data-bo-sortable-token-value');
    }

    /**
     * @return list<string>
     */
    private function shownOrder(): array
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$this->product->getId());
        $titleById = [];
        foreach (ProductSaleElementsQuery::create()->filterByProductId($this->product->getId())->find($this->getPropelConnection()) as $combination) {
            $titleById[(int) $combination->getId()] = $this->titleOf($combination);
        }

        return $crawler->filter('table[data-testid="combinations-table"] tbody tr[data-row-id]')
            ->each(static fn ($row): string => $titleById[(int) $row->attr('data-row-id')]);
    }

    /**
     * @return array<string, int> position stored for each value, in the stored order
     */
    private function storedPositions(): array
    {
        ProductSaleElementsTableMap::clearInstancePool();

        $positions = [];
        foreach (ProductSaleElementsQuery::create()->filterByProductId($this->product->getId())->orderByPosition()->orderById()->find($this->getPropelConnection()) as $combination) {
            $positions[$this->titleOf($combination)] = (int) $combination->getPosition();
        }

        return $positions;
    }

    private function combinationOf(string $title): ProductSaleElements
    {
        foreach (ProductSaleElementsQuery::create()->filterByProductId($this->product->getId())->find($this->getPropelConnection()) as $combination) {
            if ($this->titleOf($combination) === $title) {
                return $combination;
            }
        }

        self::fail(\sprintf('No combination carries the value "%s".', $title));
    }

    private function titleOf(ProductSaleElements $combination): string
    {
        foreach ($combination->getAttributeCombinations(null, $this->getPropelConnection()) as $attributeCombination) {
            foreach ($this->values as $title => $value) {
                if ((int) $attributeCombination->getAttributeAvId() === (int) $value->getId()) {
                    return $title;
                }
            }
        }

        return '';
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }
}
