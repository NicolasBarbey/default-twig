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

namespace BackOfficeDefaultTwigBundle\DTO\Report;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;

/**
 * What the "Reports > Customer loyalty" screen shows. The figures of the core
 * (Thelia\Domain\Report\Customer\*) are kept as they come, read by their public properties in
 * the template, beside what the screen builds around them: the tabs, the options and the export.
 */
final readonly class CustomerReport
{
    /**
     * @param list<array{key: string, label: string, url: string, active: bool}>   $tabs
     * @param list<array{value: string, label: string, active: bool, url: string}> $scopeOptions
     * @param list<array{value: string, label: string, active: bool, url: string}> $depthOptions
     * @param list<array{value: string, label: string, active: bool, url: string}> $unitOptions
     * @param list<array{value: string, label: string, active: bool, url: string}> $periodOptions
     * @param array<string, string>                                                $formFields
     * @param object|null                                                          $table         the CohortTable of the cohort and lifetime value tabs
     * @param object|null                                                          $summary       the LifetimeSummary of the cohorts
     * @param list<object>                                                         $segments      the SegmentRow list of the segment tab
     * @param array{url: string, from: string, to: string, languageId: int}|null   $export
     */
    public function __construct(
        public string $tab,
        public array $tabs,
        public ?string $topCustomersUrl,
        public bool $allOrders,
        public array $scopeOptions,
        public array $depthOptions,
        public array $unitOptions,
        public string $unit,
        public array $periodOptions,
        public ?DateRange $range,
        public string $formUrl,
        public array $formFields,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?object $table,
        public ?object $summary,
        public array $segments,
        public int $dormantAfterDays,
        public ?string $dormancySettingUrl,
        public string $currencyCode,
        public ?array $export,
        public \DateTimeImmutable $computedAt,
        public string $refreshUrl,
    ) {
    }
}
