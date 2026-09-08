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

/**
 * The states a customer may pick, grouped by country and alphabetical inside a
 * country. Reading them is the shared provider's job; ordering them for a select
 * element is this one's.
 */
final readonly class StateChoiceProvider
{
    public function __construct(private CountryStateProvider $countryStates)
    {
    }

    /**
     * @return list<array{id: int, country_id: int, title: string}>
     */
    public function forLocale(?string $locale = null): array
    {
        $states = $this->countryStates->visibleStates($locale);

        $collator = class_exists(\Collator::class) ? new \Collator($locale ?? 'fr_FR') : null;

        usort($states, static function (array $a, array $b) use ($collator): int {
            if ($a['country_id'] !== $b['country_id']) {
                return $a['country_id'] <=> $b['country_id'];
            }

            return $collator instanceof \Collator
                ? $collator->compare($a['title'], $b['title'])
                : strcasecmp($a['title'], $b['title']);
        });

        return $states;
    }
}
