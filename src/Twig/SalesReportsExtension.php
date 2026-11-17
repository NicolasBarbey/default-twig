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

namespace BackOfficeDefaultTwigBundle\Twig;

use BackOfficeDefaultTwigBundle\Service\Report\CustomerReportsContext;
use BackOfficeDefaultTwigBundle\Service\Report\SalesReportsContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `sales_reports_available()` and `customer_reports_available()`: whether the core carries the
 * sales reports and the customer reports, to offer their screens only then.
 */
final class SalesReportsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sales_reports_available', SalesReportsContext::coreHasSalesReports(...)),
            new TwigFunction('customer_reports_available', CustomerReportsContext::coreHasCustomerReports(...)),
        ];
    }
}
