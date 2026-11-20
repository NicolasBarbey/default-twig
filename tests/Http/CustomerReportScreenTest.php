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

use BackOfficeDefaultTwigBundle\Service\Report\CustomerReportsContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\ExportQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The "Reports > Customer loyalty" screen: the cohort table, the lifetime value, the segments,
 * the customer permission they ask for, and the export of what is on screen.
 */
final class CustomerReportScreenTest extends WebIntegrationTestCase
{
    private const COHORTS_URL = '/admin/reports/customers/cohorts';

    private const LIFETIME_URL = '/admin/reports/customers/lifetime';

    private const SEGMENTS_URL = '/admin/reports/customers/segments';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists('Thelia\Domain\Report\Customer\CustomerLifetimeCalculator')) {
            self::markTestSkipped('The core predates the customer reports: the screen then answers a message, covered below.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        CustomerReportsContext::pretendTheCalculatorIs(null);
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testTheCohortTableOpensOnTwentyFourMonthsWithTheCurrentCohortAtOneHundredPercent(): void
    {
        $customer = $this->customer();
        $this->paidOrder($customer, 'now');
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::COHORTS_URL);

        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="report-depth-24"]')->attr('class'));
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="report-unit-month"]')->attr('class'));
        foreach (['cohorts', 'lifetime', 'segments', 'top-customers'] as $tab) {
            self::assertCount(1, $crawler->filter('[data-testid="report-tab-'.$tab.'"]'), $tab);
        }
        self::assertCount(24 + 2, $crawler->filter('[data-testid="report-cohort-table"] thead th'), 'The cohort, its size and a column per month.');

        $current = $crawler->filter('[data-testid="report-cohort-row"]')->last();
        self::assertSame((new \DateTimeImmutable())->format('Y-m'), $current->filter('th')->text());
        self::assertStringContainsString('100,0 %', $current->filter('[data-testid="report-cohort-cell"]')->first()->text());
        self::assertStringContainsString('not margin', $crawler->filter('[data-testid="report-scope-label"]')->text());
        self::assertStringContainsString('except the cancelled and refunded ones', $crawler->filter('[data-testid="report-scope-label"]')->text());
        self::assertStringEndsWith(self::COHORTS_URL, (string) $crawler->filter('[data-testid="bo-nav-customer-report"]')->attr('href'));
    }

    public function testTheQuarterTableHasAColumnPerQuarter(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::COHORTS_URL.'?depth=12&unit=quarter');

        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="report-unit-quarter"]')->attr('class'));
        self::assertCount(4 + 2, $crawler->filter('[data-testid="report-cohort-table"] thead th'));
        self::assertSame('thelia.export.customer_cohorts_quarter', $this->exportRef());
    }

    public function testTheLifetimeTabGivesTheAverageValueTheLifespanAndTheRepeatRate(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::LIFETIME_URL);

        $crawler = $this->client->getCrawler();
        foreach (['report-lifetime-value', 'report-lifespan', 'report-repeat-rate', 'report-lifetime-customers', 'report-lifetime-table'] as $testid) {
            self::assertCount(1, $crawler->filter('[data-testid="'.$testid.'"]'), $testid);
        }
        self::assertSame('thelia.export.customer_lifetime_value', $this->exportRef());
    }

    public function testTheSegmentTabFollowsThePeriodAndTellsTheDormancyThreshold(): void
    {
        $previous = ConfigQuery::read('customer_dormant_after_days');
        ConfigQuery::write('customer_dormant_after_days', '90');

        try {
            $customer = $this->customer();
            $this->paidOrder($customer, '-3 days');
            $this->loginAs($this->factory->admin());

            $this->assertPageRenders(self::SEGMENTS_URL.'?period=7days');

            $crawler = $this->client->getCrawler();
            self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="report-period-7days"]')->attr('class'));
            foreach (['new', 'returning', 'lapsing', 'dormant'] as $segment) {
                self::assertCount(1, $crawler->filter('[data-testid="report-segment-'.$segment.'"]'), $segment);
            }
            self::assertGreaterThanOrEqual(1, (int) $crawler->filter('[data-testid="report-segment-new"] [data-testid="report-segment-customers"]')->text());
            self::assertStringContainsString('90 days', $crawler->filter('[data-testid="report-segments-note"]')->text());
            self::assertSame('thelia.export.customer_segments', $this->exportRef());
            self::assertStringContainsString('period=7days', (string) $crawler->filter('[data-testid="report-tab-top-customers"]')->attr('href'), 'The best customers of the same period.');
            self::assertStringContainsString('scope=paid', (string) $crawler->filter('[data-testid="report-tab-top-customers"]')->attr('href'), 'Every order on screen, yet the best customers on the paid orders, as the customer list totals them.');

            $variableId = (int) ConfigQuery::create()->findOneByName('customer_dormant_after_days')?->getId();
            $setting = (string) $crawler->filter('[data-testid="report-dormancy-setting"]')->attr('href');
            self::assertStringEndsWith('/admin/configuration/variables?edit='.$variableId, $setting);

            $this->assertPageRenders($setting);
            self::assertSame(
                '#variable-edit-modal-'.$variableId,
                $this->client->getCrawler()->filter('[data-testid="variable-edit-requested"]')->attr('data-bo-auto-open-modal-target-value'),
                'The threshold opens in its edit dialog.',
            );
        } finally {
            ConfigQuery::write('customer_dormant_after_days', $previous ?? '180');
        }
    }

    public function testThePaidScopeIsKeptAndExportedAsTheFileOfThePaidOrders(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::COHORTS_URL.'?scope=paid&unit=quarter');

        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('Only the paid orders', $crawler->filter('[data-testid="report-scope-label"]')->text());
        self::assertSame('thelia.export.customer_cohorts_quarter_paid', $this->exportRef());
        self::assertStringContainsString('scope=paid', (string) $crawler->filter('[data-testid="report-tab-lifetime"]')->attr('href'));

        // The best customers over the window of the table: the eight quarters (24 months) up to today.
        $quarterStart = ((int) ceil((int) date('n') / 3) - 1) * 3 + 1;
        $from = (new \DateTimeImmutable())->setDate((int) date('Y'), $quarterStart, 1)->modify('-21 months')->format('Y-m-d');
        parse_str((string) parse_url((string) $crawler->filter('[data-testid="report-tab-top-customers"]')->attr('href'), \PHP_URL_QUERY), $query);
        self::assertSame(['period' => 'custom', 'from' => $from, 'to' => date('Y-m-d'), 'scope' => 'paid'], $query);
    }

    public function testWithoutTheCustomerPermissionTheScreenIsRefusedAndOutOfTheMenu(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]));

        foreach ([self::COHORTS_URL, self::LIFETIME_URL, self::SEGMENTS_URL] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        $this->assertPageRenders('/admin/reports/sales/category');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="bo-nav-customer-report"]'));
    }

    public function testTheCustomerPermissionAloneReadsTheScreenWithoutTheExport(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]));

        $this->assertPageRenders(self::COHORTS_URL);

        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="bo-nav-customer-report"]'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="report-export-form"]'), 'The export asks for the export permission.');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="report-dormancy-setting"]'), 'The setting asks for the configuration permission.');
    }

    public function testOnACoreWithoutTheCustomerReportsTheScreenSaysSoAndLeavesTheMenu(): void
    {
        CustomerReportsContext::pretendTheCalculatorIs('Thelia\Domain\Report\Customer\NoSuchCalculator');
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::COHORTS_URL);

        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="report-customers-unavailable"]'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="bo-nav-customer-report"]'));
    }

    private function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function paidOrder(Customer $customer, string $at): void
    {
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => '0.000000']);
        $order->setCreatedAt(new \DateTime($at))->save($this->getPropelConnection());

        $this->factory->orderProduct($order, ['price' => '25.000000', 'quantity' => 1]);
    }

    private function exportRef(): ?string
    {
        $action = (string) $this->client->getCrawler()->filter('[data-testid="report-export-form"]')->attr('action');

        return ExportQuery::create()->findPk((int) basename((string) parse_url($action, \PHP_URL_PATH)))?->getRef();
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }
}
