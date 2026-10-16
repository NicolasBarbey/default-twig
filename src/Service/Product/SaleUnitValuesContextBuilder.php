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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use BackOfficeDefaultTwigBundle\Repository\CurrencyRepository;
use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use Thelia\Model\SaleUnitProductPrice;

/**
 * The prices and stocks one sale unit keeps of the combinations of a product, next to the
 * values they replace. A value the unit does not set is shown as the one that applies in
 * its place: the unit's price in the default currency converted, else the national value.
 * A shop without any sale unit gets no section at all.
 *
 * Each field carries the hint shown under it as a translation key and its parameters.
 */
final readonly class SaleUnitValuesContextBuilder
{
    public const HINT_NATIONAL = 'National value';
    public const HINT_NATIONAL_WAS = 'National: %value%';
    public const HINT_CONVERTED = 'Converted from %currency%';
    public const HINT_SAME_AS_PRICE = 'Same as the price';

    public function __construct(
        private SaleUnitRepository $saleUnits,
        private CurrencyRepository $currencies,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $combinationRows the rows of the combinations tab, national values in $currencyId
     *
     * @return array{
     *     sale_units: list<array{id: int, code: string, title: string, active: bool}>,
     *     sale_unit: array{id: int, code: string, title: string, active: bool},
     *     currency_id: int,
     *     rows: list<array<string, mixed>>
     * }|null
     */
    public function build(array $combinationRows, int $currencyId, ?int $saleUnitId, string $locale): ?array
    {
        $options = $this->saleUnits->findOptions($locale);
        if ([] === $options) {
            return null;
        }

        $selected = $options[0];
        foreach ($options as $option) {
            if ($option['id'] === $saleUnitId) {
                $selected = $option;
                break;
            }
        }

        $currency = $this->currencies->find($currencyId);
        $default = $this->currencies->findDefault();
        // Another currency is converted the way the core resolves a unit price: the unit's
        // default-currency row, times the rate of the asked currency over the default one.
        $converts = null !== $currency && null !== $default && $currency->getId() !== $default->getId() && (float) $default->getRate() > 0;
        $rate = $converts ? (float) $currency->getRate() / (float) $default->getRate() : 1.0;
        $defaultId = (int) $default?->getId();
        $defaultCode = (string) $default?->getCode();

        $pseIds = array_map(static fn (array $row): int => (int) $row['id'], $combinationRows);
        $prices = $this->saleUnits->findPrices($selected['id'], $pseIds, $converts ? [$currencyId, $defaultId] : [$currencyId]);
        $stocks = $this->saleUnits->findStocks($selected['id'], $pseIds);

        $rows = [];
        foreach ($combinationRows as $row) {
            $pseId = (int) $row['id'];
            $own = $prices[$currencyId][$pseId] ?? null;
            if (null !== $own && $converts && $own->getFromDefaultCurrency()) {
                $own = null;
            }
            $fromDefault = $converts && null === $own ? ($prices[$defaultId][$pseId] ?? null) : null;
            $stock = $stocks[$pseId] ?? null;

            $rows[] = [
                'id' => $pseId,
                'label' => (string) $row['label'],
                'ref' => (string) $row['ref'],
                'fields' => [
                    $this->priceField($own, $fromDefault, $rate, $defaultCode, $row['price'] ?? null),
                    $this->promoPriceField($own, $fromDefault, $rate, $defaultCode, $row['sale_price'] ?? null),
                    self::field('quantity', self::number($stock?->getQuantity()), self::number($row['quantity'] ?? null)),
                ],
            ];
        }

        return [
            'sale_units' => $options,
            'sale_unit' => $selected,
            'currency_id' => $currencyId,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceField(?SaleUnitProductPrice $own, ?SaleUnitProductPrice $fromDefault, float $rate, string $defaultCode, mixed $national): array
    {
        if (null !== $own) {
            return self::field('price', self::number($own->getPrice()), self::number($national));
        }

        if (null !== $fromDefault) {
            return self::field('price', null, self::number((float) $fromDefault->getPrice() * $rate), self::HINT_CONVERTED, ['%currency%' => $defaultCode]);
        }

        return self::field('price', null, self::number($national));
    }

    /**
     * A unit stores a promo price equal to its price when none was typed: that value is
     * shown as an empty field, so a later change of the price carries the promo price along.
     *
     * @return array<string, mixed>
     */
    private function promoPriceField(?SaleUnitProductPrice $own, ?SaleUnitProductPrice $fromDefault, float $rate, string $defaultCode, mixed $national): array
    {
        if (null !== $own) {
            $price = self::number($own->getPrice());
            $promo = self::number($own->getPromoPrice());

            return $promo === $price
                ? self::field('promo_price', null, $price, self::HINT_SAME_AS_PRICE)
                : self::field('promo_price', $promo, self::number($national));
        }

        if (null !== $fromDefault) {
            return self::field('promo_price', null, self::number((float) $fromDefault->getPromoPrice() * $rate), self::HINT_CONVERTED, ['%currency%' => $defaultCode]);
        }

        return self::field('promo_price', null, self::number($national));
    }

    /**
     * @param array<string, string> $hintParameters
     *
     * @return array{name: string, value: ?string, placeholder: ?string, hint: string, hint_parameters: array<string, string>}
     */
    private static function field(string $name, ?string $value, ?string $fallback, ?string $hint = null, array $hintParameters = []): array
    {
        if (null === $hint) {
            [$hint, $hintParameters] = null === $value
                ? [self::HINT_NATIONAL, []]
                : [self::HINT_NATIONAL_WAS, ['%value%' => $fallback ?? '-']];
        }

        return [
            'name' => $name,
            'value' => $value,
            'placeholder' => null === $value ? $fallback : null,
            'hint' => $hint,
            'hint_parameters' => $hintParameters,
        ];
    }

    /**
     * A decimal column read back without its trailing zeros, null when there is no value:
     * an empty field is what says "the value in its place applies".
     */
    private static function number(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
