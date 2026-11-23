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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Customer;

use BackOfficeDefaultTwigBundle\Service\Customer\CustomerCell;
use PHPUnit\Framework\TestCase;

/** The customer as every list of the back office shows them. */
final class CustomerCellTest extends TestCase
{
    public function testTheNameOpensTheCustomerSheetWhenAUrlIsGiven(): void
    {
        $html = CustomerCell::html('/admin/customer/update?customer_id=7', 'Marie', 'Mesmer', 'marie@example.test');

        self::assertStringContainsString(
            '<a href="/admin/customer/update?customer_id=7" class="text-decoration-none" data-testid="bo-customer-name-link">Marie Mesmer</a>',
            $html,
        );
    }

    public function testTheNameStaysPlainTextWithoutUrl(): void
    {
        $html = CustomerCell::html(null, 'Marie', 'Mesmer', 'marie@example.test');

        self::assertStringContainsString('<div class="fw-semibold">Marie Mesmer</div>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testTheEMailReadsUnderTheNameWithItsFullAddressOnHover(): void
    {
        $html = CustomerCell::html(null, 'Marie', 'Mesmer', 'a.very.long.address@an-example-shop.test');

        self::assertStringContainsString(
            '<div class="text-muted small text-truncate" title="a.very.long.address@an-example-shop.test">a.very.long.address@an-example-shop.test</div>',
            $html,
        );
    }

    public function testACustomerWithoutNameReadsAsTheirEMail(): void
    {
        $html = CustomerCell::html(null, '', '', 'marie@example.test');

        self::assertStringContainsString('<div class="fw-semibold">marie@example.test</div>', $html);
    }

    public function testTheCompactCellIsMarkedForTheNarrowerEMail(): void
    {
        self::assertStringStartsWith('<div class="bo-customer-cell bo-customer-cell--compact">', CustomerCell::html(null, 'Marie', 'Mesmer', 'marie@example.test', compact: true));
        self::assertStringStartsWith('<div class="bo-customer-cell">', CustomerCell::html(null, 'Marie', 'Mesmer', 'marie@example.test'));
    }

    public function testTheFlagOfTheCountryStandsBeforeTheName(): void
    {
        $html = CustomerCell::html(null, 'Marie', 'Mesmer', 'marie@example.test', 'FR', 'France');

        self::assertStringContainsString('<div class="fw-semibold"><span class="bo-country-flag" data-bs-toggle="tooltip" title="France">', $html);
    }

    public function testNamesAndAddressesAreEscaped(): void
    {
        $html = CustomerCell::html('/admin/customer/update?customer_id=7&tab="x"', '<b>Marie</b>', 'O\'Hara', '"x"@example.test');

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;Marie&lt;/b&gt; O&#039;Hara', $html);
        self::assertStringContainsString('href="/admin/customer/update?customer_id=7&amp;tab=&quot;x&quot;"', $html);
        self::assertStringContainsString('title="&quot;x&quot;@example.test"', $html);
    }
}
