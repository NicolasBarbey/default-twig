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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Service\I18n\CountryStateProvider;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Model\CountryQuery;
use Thelia\Model\StateQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The country and state lists behind every address form in the back office.
 *
 * A shop shipping worldwide carries a few hundred countries and as many states,
 * and both lists are built on any screen showing an address: an order, a customer,
 * a coupon condition. Read one row at a time they cost one query each, so these
 * tests pin the budget rather than the output.
 */
final class CountryStateProviderTest extends IntegrationTestCase
{
    private CountryStateProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new CountryStateProvider();
    }

    public function testTheWholeCountryListWithItsTitlesCostsOneQuery(): void
    {
        $countries = [];

        $queries = QueryCounter::count(function () use (&$countries): void {
            $countries = $this->provider->countries('en_US');
        });

        self::assertGreaterThan(1, \count($countries), 'The seeded shop has more than one country.');
        self::assertSame(1, $queries, 'Reading the countries and their titles is one joined query.');
    }

    public function testTheWholeStateListWithItsTitlesCostsOneQuery(): void
    {
        $states = [];

        $queries = QueryCounter::count(function () use (&$states): void {
            $states = $this->provider->states('en_US');
        });

        self::assertGreaterThan(1, \count($states), 'The seeded shop has more than one state.');
        self::assertSame(1, $queries, 'Reading the states and their titles is one joined query.');
    }

    public function testTheSecondReaderOfTheSameRequestPaysNothing(): void
    {
        $this->provider->countries('en_US');
        $this->provider->states('en_US');

        $queries = QueryCounter::count(function (): void {
            // What an order page does: the country and state lists are built for the
            // invoice address, then again for the delivery address.
            $this->provider->countries('en_US');
            $this->provider->visibleCountries('en_US');
            $this->provider->countryTitles('en_US');
            $this->provider->states('en_US');
            $this->provider->visibleStates('en_US');
            $this->provider->stateTitles('en_US');
        });

        self::assertSame(0, $queries, 'The lists are read once per locale and per request.');
    }

    public function testEachLocaleIsReadOnItsOwn(): void
    {
        $this->provider->countries('en_US');

        $queries = QueryCounter::count(function (): void {
            $this->provider->countries('fr_FR');
            $this->provider->countries('fr_FR');
        });

        self::assertSame(1, $queries, 'A second admin language costs one more query, not one per country.');
    }

    public function testTitlesMatchWhatEachRowAnswersOnItsOwn(): void
    {
        $expected = [];
        foreach (CountryQuery::create()->orderById()->find() as $country) {
            $country->setLocale('en_US');
            $expected[(int) $country->getId()] = (string) $country->getTitle();
        }

        self::assertSame($expected, $this->provider->countryTitles('en_US'));
    }

    public function testStateTitlesMatchWhatEachRowAnswersOnItsOwn(): void
    {
        $expected = [];
        foreach (StateQuery::create()->orderByCountryId()->orderById()->find() as $state) {
            $state->setLocale('en_US');
            $expected[(int) $state->getId()] = (string) $state->getTitle();
        }

        self::assertSame($expected, $this->provider->stateTitles('en_US'));
    }

    public function testAnUntranslatedLocaleAnswersWithEmptyTitlesRatherThanBorrowedOnes(): void
    {
        $titles = $this->provider->countryTitles('zz_ZZ');

        self::assertNotSame([], $titles);
        self::assertSame([''], array_values(array_unique($titles)));
    }

    public function testHiddenCountriesAndStatesAreLeftOutOfTheCustomerFacingLists(): void
    {
        $hiddenCountry = $this->createFixtureFactory()->country(['visible' => 0]);
        $hiddenCountryId = (int) $hiddenCountry->getId();

        $allIds = array_column($this->provider->countries('en_US'), 'id');
        $visibleIds = array_column($this->provider->visibleCountries('en_US'), 'id');

        self::assertContains($hiddenCountryId, $allIds);
        self::assertNotContains($hiddenCountryId, $visibleIds);
    }
}
