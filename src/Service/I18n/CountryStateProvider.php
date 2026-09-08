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

namespace BackOfficeDefaultTwigBundle\Service\I18n;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\CountryQuery;
use Thelia\Model\StateQuery;

/**
 * The countries and the states of the shop, titles included, read once per request.
 *
 * Every back-office screen carrying an address form needs the whole of both lists,
 * and a shop shipping worldwide has a few hundred rows in each. Reading one row at a
 * time — setLocale() then getTitle() — costs one query per country and one per state
 * on every one of those screens. joinWithI18n() brings a whole list back in a single
 * query, and memoising per locale means the second reader of the same request pays
 * nothing: an order page draws its country and state lists twice over, once for the
 * invoice address and once for the delivery address.
 *
 * A row with no translation in the asked locale answers with an empty title, as it
 * did when it was read on its own: the back office shows what the shop has actually
 * translated, never a title borrowed from another locale.
 */
final class CountryStateProvider
{
    /**
     * The locale Propel reads translations in when nobody has set one. Callers with
     * no locale to offer land here, which is where they landed when they read the
     * rows themselves.
     */
    public const DEFAULT_LOCALE = 'en_US';

    /** @var array<string, list<array{id: int, title: string, iso: string, visible: bool}>> */
    private array $countries = [];

    /** @var array<string, list<array{id: int, country_id: int, title: string, visible: bool}>> */
    private array $states = [];

    /**
     * Every country of the shop, hidden ones included, by ascending id.
     *
     * @return list<array{id: int, title: string, iso: string}>
     */
    public function countries(?string $locale = null): array
    {
        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'iso' => $row['iso']],
            $this->loadedCountries($locale),
        );
    }

    /**
     * The countries a customer may pick, by ascending id.
     *
     * @return list<array{id: int, title: string, iso: string}>
     */
    public function visibleCountries(?string $locale = null): array
    {
        return array_values(array_map(
            static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'iso' => $row['iso']],
            array_filter($this->loadedCountries($locale), static fn (array $row): bool => $row['visible']),
        ));
    }

    /**
     * Every state of the shop, grouped by country, by ascending id inside a country.
     *
     * @return list<array{id: int, country_id: int, title: string}>
     */
    public function states(?string $locale = null): array
    {
        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'country_id' => $row['country_id'], 'title' => $row['title']],
            $this->loadedStates($locale),
        );
    }

    /**
     * The states a customer may pick, grouped by country.
     *
     * @return list<array{id: int, country_id: int, title: string}>
     */
    public function visibleStates(?string $locale = null): array
    {
        return array_values(array_map(
            static fn (array $row): array => ['id' => $row['id'], 'country_id' => $row['country_id'], 'title' => $row['title']],
            array_filter($this->loadedStates($locale), static fn (array $row): bool => $row['visible']),
        ));
    }

    /**
     * Country titles indexed by id, for the screens that resolve a handful of
     * country ids read from another table.
     *
     * @return array<int, string>
     */
    public function countryTitles(?string $locale = null): array
    {
        $titles = [];
        foreach ($this->loadedCountries($locale) as $row) {
            $titles[$row['id']] = $row['title'];
        }

        return $titles;
    }

    /**
     * State titles indexed by id.
     *
     * @return array<int, string>
     */
    public function stateTitles(?string $locale = null): array
    {
        $titles = [];
        foreach ($this->loadedStates($locale) as $row) {
            $titles[$row['id']] = $row['title'];
        }

        return $titles;
    }

    /**
     * @return list<array{id: int, title: string, iso: string, visible: bool}>
     */
    private function loadedCountries(?string $locale): array
    {
        $locale ??= self::DEFAULT_LOCALE;

        return $this->countries[$locale] ??= $this->readCountries($locale);
    }

    /**
     * @return list<array{id: int, country_id: int, title: string, visible: bool}>
     */
    private function loadedStates(?string $locale): array
    {
        $locale ??= self::DEFAULT_LOCALE;

        return $this->states[$locale] ??= $this->readStates($locale);
    }

    /**
     * @return list<array{id: int, title: string, iso: string, visible: bool}>
     */
    private function readCountries(string $locale): array
    {
        // Ordering by id is what the unordered read used to give back, and the readers
        // that sort by title rely on it for their ties: PHP sorts are stable, so two
        // countries sharing a title keep coming out in the same order as before.
        $rows = [];
        $countries = CountryQuery::create()
            ->orderById()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($countries as $country) {
            $country->setLocale($locale);
            $rows[] = [
                'id' => (int) $country->getId(),
                'title' => (string) $country->getTitle(),
                'iso' => (string) $country->getIsoalpha2(),
                'visible' => (bool) $country->getVisible(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, country_id: int, title: string, visible: bool}>
     */
    private function readStates(string $locale): array
    {
        $rows = [];
        $states = StateQuery::create()
            ->orderByCountryId()
            ->orderById()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($states as $state) {
            $state->setLocale($locale);
            $rows[] = [
                'id' => (int) $state->getId(),
                'country_id' => (int) $state->getCountryId(),
                'title' => (string) $state->getTitle(),
                'visible' => (bool) $state->getVisible(),
            ];
        }

        return $rows;
    }
}
