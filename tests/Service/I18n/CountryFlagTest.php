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

namespace BackOfficeDefaultTwigBundle\Tests\Service\I18n;

use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;
use PHPUnit\Framework\TestCase;

final class CountryFlagTest extends TestCase
{
    public function testTheFlagCarriesTheNameOfTheCountryOnHover(): void
    {
        self::assertSame(
            '<span class="bo-country-flag" data-bs-toggle="tooltip" title="Belgique">'.CountryFlag::of('BE').'</span> ',
            CountryFlag::named('BE', 'Belgique'),
        );
    }

    public function testTheNameOfTheCountryIsEscaped(): void
    {
        self::assertStringContainsString('title="France &lt;&quot;Métropole&quot;&gt;"', CountryFlag::named('FR', 'France <"Métropole">'));
    }

    public function testACountryWithoutNameInThisLocaleKeepsItsBareFlag(): void
    {
        self::assertSame(CountryFlag::prefix('FR'), CountryFlag::named('FR', ''));
    }

    public function testNoFlagNoName(): void
    {
        self::assertSame('', CountryFlag::named(null, 'Somewhere'));
        self::assertSame('', CountryFlag::named('XYZ', 'Somewhere'));
    }
}
