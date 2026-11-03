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
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Twig\Environment;

/** What a module adds to the combinations grid: a column through the hooks, and fields in the folded template. */
final class ProductCombinationGridExtensionTest extends WebIntegrationTestCase
{
    private const HEADER_HOOK_EVENT = 'hook.'.TemplateDefinition::BACK_OFFICE.'.product.combinations-list-caption';

    private const ROW_HOOK_EVENT = 'hook.'.TemplateDefinition::BACK_OFFICE.'.product.combinations-row';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    /** @var list<array{string, callable}> */
    private array $listeners = [];

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
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        foreach ($this->listeners as [$eventName, $listener]) {
            $dispatcher->removeListener($eventName, $listener);
        }
        $this->injector->clear();

        parent::tearDown();
    }

    public function testAModuleAddsAColumnRightBeforeTheActions(): void
    {
        [$product, $combination] = $this->productWithACombination();

        $this->listen(self::HEADER_HOOK_EVENT, static function (HookRenderEvent $event): void {
            $event->add(\sprintf('<th data-testid="module-column-header" data-product="%d">Supplier stock</th>', (int) $event->getArgument('product_id')));
        });
        $this->listen(self::ROW_HOOK_EVENT, static function (HookRenderEvent $event): void {
            $event->add(\sprintf('<td rowspan="3" data-testid="module-cell-%d">12</td>', (int) $event->getArgument('pse_id')));
        });

        $grid = $this->tab($product)->filter('table[data-testid="combinations-table"]');

        $header = $grid->filter('thead > tr > th')->each(static fn (Crawler $cell): string => (string) ($cell->attr('data-testid') ?? $cell->attr('class')));
        self::assertSame('module-column-header', $header[\count($header) - 2], 'The header of the module comes right before the one of the actions, in the header line.');
        self::assertSame('bo-combinations-grid__col-actions', $header[\count($header) - 1]);
        self::assertSame((string) $product->getId(), $grid->filter('[data-testid="module-column-header"]')->attr('data-product'), 'The header hook hands the module the product.');

        $titleLine = $grid->filter(\sprintf('tbody[data-row-id="%d"] > tr', $combination->getId()))->first();
        $cells = $titleLine->filter('td')->each(static fn (Crawler $cell): string => (string) ($cell->attr('data-testid') ?? $cell->attr('class')));
        self::assertSame('module-cell-'.$combination->getId(), $cells[\count($cells) - 2], 'The cell of the module comes right before the actions, on the title line of its combination.');
        self::assertStringContainsString('bo-combinations-grid__actions', $cells[\count($cells) - 1]);
    }

    public function testEveryLineOfACombinationSpansTheColumnsOfTheModulesToo(): void
    {
        [$product, $combination] = $this->productWithACombination();
        $other = $this->factory->productSaleElement($product);
        $this->factory->attributeCombination($other, $this->factory->attributeAv($this->factory->attribute(['title' => 'Color']), ['title' => 'Red']));

        $this->listen(self::HEADER_HOOK_EVENT, static function (HookRenderEvent $event): void {
            $event->add('<th>Supplier stock</th>');
        });
        $this->listen(self::ROW_HOOK_EVENT, static function (HookRenderEvent $event): void {
            $event->add('<td rowspan="3">12</td>');
        });

        $grid = $this->tab($product)->filter('table[data-testid="combinations-table"]');
        $columns = array_sum($grid->filter('thead > tr > th')->each(static fn (Crawler $cell): int => (int) ($cell->attr('colspan') ?? 1)));
        self::assertSame(9, $columns, 'The 8 columns of the grid and the one of the module.');

        foreach ([$combination, $other] as $pse) {
            $lines = $this->widthsOfTheLines($grid->filter(\sprintf('tbody[data-row-id="%d"]', $pse->getId())));
            self::assertCount(5, $lines);
            self::assertSame(array_fill(0, 5, $columns), $lines, 'Each line of the combination, the folded fields and the air under it included, spans the grid.');
        }
    }

    public function testTheOtherFieldsOfACombinationComeFromATemplateOfTheirOwn(): void
    {
        [$product, $combination] = $this->productWithACombination();

        $folded = $this->tab($product)->filter(\sprintf('tr[data-testid="combinations-more-%d"] .bo-combinations-grid__more-fields', $combination->getId()));
        self::assertCount(1, $folded->filter('input[name="mpn[]"]'));
        self::assertCount(1, $folded->filter('select[name="manufacturer_brand_id[]"]'));

        // An override is a copy of the template: it renders with what the tab hands it, nothing else.
        $html = $this->getService(Environment::class)->render('@BackOfficeDefaultTwig/catalog/product/_combination_more_fields.html.twig', [
            'row' => ['id' => 12, 'mpn' => 'MPN-12', 'manufacturer_brand_id' => 3],
            'field_id' => 'combination-12',
            'brand_options' => [['id' => 3, 'title' => 'Acme'], ['id' => 4, 'title' => 'Other']],
            'product_brand_title' => null,
        ]);
        $fields = new Crawler($html);
        self::assertSame('MPN-12', $fields->filter('input#combination-12-mpn')->attr('value'));
        self::assertSame('3', $fields->filter('select#combination-12-manufacturer option[selected]')->attr('value'));
    }

    /**
     * The number of columns each line of a combination covers, the cells spanning down from a line above counted in.
     *
     * @return list<int>
     */
    private function widthsOfTheLines(Crawler $combination): array
    {
        $taken = [];
        $widths = [];
        foreach ($combination->filter('tr') as $line => $row) {
            $column = 0;
            foreach ((new Crawler($row))->children('td, th') as $cell) {
                while (isset($taken[$line][$column])) {
                    ++$column;
                }
                $rows = max(1, (int) $cell->getAttribute('rowspan'));
                $columns = max(1, (int) $cell->getAttribute('colspan'));
                for ($down = 0; $down < $rows; ++$down) {
                    for ($across = 0; $across < $columns; ++$across) {
                        $taken[$line + $down][$column + $across] = true;
                    }
                }
                $column += $columns;
            }
            $widths[] = \count($taken[$line] ?? []);
        }

        return $widths;
    }

    private function tab(Product $product): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function listen(string $eventName, callable $listener): void
    {
        $this->getService(EventDispatcherInterface::class)->addListener($eventName, $listener);
        $this->listeners[] = [$eventName, $listener];
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
