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

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\Service\I18n\CountryStateProvider;

final readonly class CountryRepository
{
    public function __construct(private CountryStateProvider $countryStates)
    {
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array{id: int, title: string, iso: string}>
     */
    public function findByIdsLocalized(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $wanted = array_fill_keys($ids, true);

        $items = array_values(array_filter(
            $this->countryStates->countries($locale),
            static fn (array $country): bool => isset($wanted[$country['id']]),
        ));

        usort($items, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $items;
    }
}
