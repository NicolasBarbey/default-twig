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

namespace BackOfficeDefaultTwigBundle\Service\Report;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\DTO\Report\CustomerReport;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Dashboard\PeriodOptions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;

/**
 * The "Reports > Customer loyalty" screen: the cohort table, the lifetime value of the cohorts and
 * the new, returning, lapsing and dormant customers of a period. The figures are the core's
 * (Thelia\Domain\Report\Customer\CustomerLifetimeCalculator): the theme holds no SQL of a report.
 * The best customers of a period are the "By customer" tab of the sales report, linked from here.
 *
 * The theme installs on a core that may predate the calculator: it is built only once the core is
 * known to carry it, and named by a string, so that a core without it never loads it.
 */
final readonly class CustomerReportProvider
{
    public const ROUTE_COHORTS = 'admin.reports.customers.cohorts';

    public const ROUTE_LIFETIME = 'admin.reports.customers.lifetime';

    public const ROUTE_SEGMENTS = 'admin.reports.customers.segments';

    public const TAB_COHORTS = 'cohorts';

    public const TAB_LIFETIME = 'lifetime';

    public const TAB_SEGMENTS = 'segments';

    /** The months of first orders the cohort tables go back to, the current one included. */
    public const DEPTHS = [12, 24, 36];

    public const DEFAULT_DEPTH = 24;

    public const UNIT_MONTH = 'month';

    public const UNIT_QUARTER = 'quarter';

    /** As the sales reports: kept 15 minutes, under a key that changes with the orders. */
    public const KEPT_SECONDS = 900;

    private const ROUTES = [
        self::TAB_COHORTS => self::ROUTE_COHORTS,
        self::TAB_LIFETIME => self::ROUTE_LIFETIME,
        self::TAB_SEGMENTS => self::ROUTE_SEGMENTS,
    ];

    private const EXPORT_REFS = [
        self::TAB_COHORTS => 'thelia.export.customer_cohorts',
        self::TAB_LIFETIME => 'thelia.export.customer_lifetime_value',
        self::TAB_SEGMENTS => 'thelia.export.customer_segments',
    ];

    public function __construct(
        private AdminSaleUnitScope $saleUnitScope,
        private CacheInterface $cache,
        private ClockInterface $clock,
        private OrderRepository $orders,
        private PeriodOptions $periodOptions,
        private ReportScreen $screen,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public static function routeOf(string $tab): string
    {
        return self::ROUTES[$tab];
    }

    public static function depth(int $requested): int
    {
        return \in_array($requested, self::DEPTHS, true) ? $requested : self::DEFAULT_DEPTH;
    }

    public static function unit(?string $requested): string
    {
        return self::UNIT_QUARTER === $requested ? self::UNIT_QUARTER : self::UNIT_MONTH;
    }

    public function compute(string $tab, int $depth, string $unit, bool $allOrders, DateRange $range, string $locale, bool $refresh = false): CustomerReport
    {
        $granularity = \Thelia\Domain\Report\Customer\CohortGranularity::from($unit);
        $today = $this->clock->now();
        $scope = $allOrders ? \Thelia\Domain\Report\Sales\StatusScope::All : \Thelia\Domain\Report\Sales\StatusScope::Paid;
        $saleUnitIds = $this->saleUnitScope->allowedSaleUnitIds();

        if (self::TAB_SEGMENTS === $tab) {
            $from = $range->from;
            $to = $range->to;
        } else {
            // The current month (or quarter) and the ones before it, as many as the depth holds.
            $from = $granularity->startOf($granularity->indexOf($today) - intdiv($depth, $granularity->months()) + 1);
            $to = $today->setTime(23, 59, 59);
        }

        $criteria = new \Thelia\Domain\Report\Sales\SalesReportCriteria($from, $to, $scope, $saleUnitIds);
        $dormantAfterDays = \Thelia\Domain\Report\Customer\CustomerDormancy::days();

        $key = 'bo_customer_report_'.hash('xxh128', serialize([
            $tab, $from->format(\DATE_ATOM), $to->format(\DATE_ATOM), $scope->value, $saleUnitIds,
            $unit, $dormantAfterDays, $this->orders->changeFingerprint(),
        ]));

        if ($refresh) {
            $this->cache->delete($key);
        }

        $figures = $this->cache->get($key, function (ItemInterface $item) use ($tab, $criteria, $granularity, $dormantAfterDays): array {
            $item->expiresAfter(self::KEPT_SECONDS);
            $calculator = new \Thelia\Domain\Report\Customer\CustomerLifetimeCalculator();

            return [
                'computedAt' => $this->clock->now(),
                'table' => self::TAB_SEGMENTS === $tab ? null : $calculator->cohorts($criteria, $granularity),
                'summary' => self::TAB_LIFETIME === $tab ? $calculator->lifetime($criteria) : null,
                'segments' => self::TAB_SEGMENTS === $tab ? $calculator->segments($criteria, $dormantAfterDays) : [],
            ];
        });

        $kept = $this->keptParameters($tab, $depth, $unit, $allOrders, $range);

        return new CustomerReport(
            tab: $tab,
            tabs: $this->tabs($tab, $depth, $unit, $allOrders),
            topCustomersUrl: SalesReportsContext::coreHasSalesReports()
                ? $this->urls->generate(SalesReportProvider::ROUTE_CUSTOMER, $this->topCustomersParameters($tab, $range, $from, $to) + ReportScreen::scopeParameters(false))
                : null,
            allOrders: $allOrders,
            scopeOptions: $this->scopeOptions($tab, $depth, $unit, $allOrders, $range),
            depthOptions: $this->depthOptions($tab, $depth, $unit, $allOrders),
            unitOptions: $this->unitOptions($tab, $depth, $unit, $allOrders),
            unit: $unit,
            periodOptions: self::TAB_SEGMENTS === $tab ? $this->periodOptions->build($range, self::ROUTE_SEGMENTS, ReportScreen::scopeParameters($allOrders)) : [],
            range: self::TAB_SEGMENTS === $tab ? $range : null,
            formUrl: $this->urls->generate(self::ROUTES[$tab]),
            formFields: ReportScreen::scopeParameters($allOrders),
            from: $from,
            to: $to,
            table: $figures['table'],
            summary: $figures['summary'],
            segments: $figures['segments'],
            dormantAfterDays: $dormantAfterDays,
            dormancySettingUrl: $this->dormancySettingUrl(),
            currencyCode: (string) \Thelia\Model\Currency::getDefaultCurrency()->getCode(),
            export: $this->export($tab, $unit, $allOrders, $from, $to, $locale),
            computedAt: $figures['computedAt'],
            refreshUrl: $this->urls->generate(self::ROUTES[$tab], $kept + ['refresh' => '1']),
        );
    }

    /**
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    private function tabs(string $current, int $depth, string $unit, bool $allOrders): array
    {
        $labels = [
            self::TAB_COHORTS => $this->translator->trans('Cohorts'),
            self::TAB_LIFETIME => $this->translator->trans('Lifetime value'),
            self::TAB_SEGMENTS => $this->translator->trans('Segments'),
        ];

        $tabs = [];
        foreach ($labels as $key => $label) {
            $parameters = self::TAB_SEGMENTS === $key ? ReportScreen::scopeParameters($allOrders) : $this->cohortParameters($depth, $unit) + ReportScreen::scopeParameters($allOrders);

            $tabs[] = [
                'key' => $key,
                'label' => $label,
                'url' => $this->urls->generate(self::ROUTES[$key], $parameters),
                'active' => $key === $current,
            ];
        }

        return $tabs;
    }

    /**
     * @return list<array{value: string, label: string, active: bool, url: string}>
     */
    private function scopeOptions(string $tab, int $depth, string $unit, bool $allOrders, DateRange $range): array
    {
        $options = [];
        foreach (['all' => 'All orders', ReportScreen::SCOPE_PAID => 'Paid orders'] as $value => $label) {
            $all = 'all' === $value;
            $parameters = $this->keptParameters($tab, $depth, $unit, $all, $range);

            $options[] = [
                'value' => $value,
                'label' => $this->translator->trans($label),
                'active' => $all === $allOrders,
                'url' => $this->urls->generate(self::ROUTES[$tab], $parameters),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, active: bool, url: string}>
     */
    private function depthOptions(string $tab, int $depth, string $unit, bool $allOrders): array
    {
        if (self::TAB_SEGMENTS === $tab) {
            return [];
        }

        $options = [];
        foreach (self::DEPTHS as $value) {
            $options[] = [
                'value' => (string) $value,
                'label' => $this->translator->trans('%count% months', ['%count%' => $value]),
                'active' => $value === $depth,
                'url' => $this->urls->generate(self::ROUTES[$tab], $this->cohortParameters($value, $unit) + ReportScreen::scopeParameters($allOrders)),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, active: bool, url: string}>
     */
    private function unitOptions(string $tab, int $depth, string $unit, bool $allOrders): array
    {
        if (self::TAB_SEGMENTS === $tab) {
            return [];
        }

        $options = [];
        foreach ([self::UNIT_MONTH => 'By month', self::UNIT_QUARTER => 'By quarter'] as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $this->translator->trans($label),
                'active' => $value === $unit,
                'url' => $this->urls->generate(self::ROUTES[$tab], $this->cohortParameters($depth, $value) + ReportScreen::scopeParameters($allOrders)),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function keptParameters(string $tab, int $depth, string $unit, bool $allOrders, DateRange $range): array
    {
        if (self::TAB_SEGMENTS === $tab) {
            return ReportScreen::periodParameters($range) + ReportScreen::scopeParameters($allOrders);
        }

        return $this->cohortParameters($depth, $unit) + ReportScreen::scopeParameters($allOrders);
    }

    /**
     * @return array<string, string>
     */
    private function cohortParameters(int $depth, string $unit): array
    {
        return ['depth' => (string) $depth, 'unit' => $unit];
    }

    /**
     * The best customers over the days on screen: the period of the segments, or the window of the
     * cohorts (36 months at most, within the 1096 days a custom period of the sales report takes).
     * The link always opens the paid orders: the sales report of every order counts the cancelled,
     * refunded and unpaid orders, the total spent of the customer list does not.
     *
     * @return array<string, string>
     */
    private function topCustomersParameters(string $tab, DateRange $range, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (self::TAB_SEGMENTS === $tab) {
            return ReportScreen::periodParameters($range);
        }

        return ['period' => DateRange::PRESET_CUSTOM, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    /**
     * The dormancy threshold in its edit dialog, for an administrator who may change it.
     */
    private function dormancySettingUrl(): ?string
    {
        if (!$this->screen->canView(AdminResources::CONFIG)) {
            return null;
        }

        $variableId = \Thelia\Model\ConfigQuery::create()->findOneByName(\Thelia\Domain\Report\Customer\CustomerDormancy::CONFIG_KEY)?->getId();

        return $this->urls->generate('admin.configuration.variables.default', null === $variableId ? [] : ['edit' => $variableId]);
    }

    /**
     * The export of the tab over the days on screen, in the unit and on the orders on screen.
     *
     * @return array{url: string, from: string, to: string, languageId: int}|null
     */
    private function export(string $tab, string $unit, bool $allOrders, \DateTimeImmutable $from, \DateTimeImmutable $to, string $locale): ?array
    {
        $ref = self::EXPORT_REFS[$tab]
            .(self::TAB_SEGMENTS !== $tab && self::UNIT_QUARTER === $unit ? '_quarter' : '')
            .($allOrders ? '' : '_'.ReportScreen::SCOPE_PAID);

        return $this->screen->export($ref, $from, $to, $locale);
    }
}
