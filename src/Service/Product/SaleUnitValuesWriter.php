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

use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use Propel\Runtime\Propel;
use Thelia\Model\Map\SaleUnitProductPriceTableMap;
use Thelia\Model\SaleUnitProductPrice;
use Thelia\Model\SaleUnitProductSaleElements;

/**
 * Writes the price and the stock one sale unit keeps of a combination. An empty value
 * removes the unit's own row, and the national value applies again: that fallback is the
 * rule of the whole feature, the own row the exception.
 */
final readonly class SaleUnitValuesWriter
{
    public function __construct(
        private SaleUnitRepository $saleUnits,
    ) {
    }

    /**
     * A promo price left empty next to a price sells at that price when the combination is
     * on sale: a unit row always carries both, and a zero there would sell for free.
     *
     * @return bool whether a row was written or deleted
     *
     * @throws \InvalidArgumentException when a promo price comes without a price, or a value is not a number
     */
    public function save(int $saleUnitId, int $currencyId, int $productSaleElementsId, ?string $price, ?string $promoPrice, ?string $quantity): bool
    {
        $price = self::decimal($price, allowNegative: false);
        $promoPrice = self::decimal($promoPrice, allowNegative: false);
        $quantity = self::decimal($quantity, allowNegative: true);

        if (null === $price && null !== $promoPrice) {
            throw new \InvalidArgumentException('A promo price needs a price.');
        }

        $connection = Propel::getConnection(SaleUnitProductPriceTableMap::DATABASE_NAME);
        $connection->beginTransaction();
        $written = false;

        try {
            $priceRow = $this->saleUnits->findPrice($saleUnitId, $productSaleElementsId, $currencyId);
            if (null === $price) {
                if (null !== $priceRow) {
                    $priceRow->delete($connection);
                    $written = true;
                }
            } else {
                $written = ($priceRow ?? (new SaleUnitProductPrice())
                    ->setSaleUnitId($saleUnitId)
                    ->setProductSaleElementsId($productSaleElementsId)
                    ->setCurrencyId($currencyId))
                    ->setPrice($price)
                    ->setPromoPrice($promoPrice ?? $price)
                    ->setFromDefaultCurrency(false)
                    ->save($connection) > 0;
            }

            $stockRow = $this->saleUnits->findStock($saleUnitId, $productSaleElementsId);
            if (null === $quantity) {
                if (null !== $stockRow) {
                    $stockRow->delete($connection);
                    $written = true;
                }
            } else {
                $written = ($stockRow ?? (new SaleUnitProductSaleElements())
                    ->setSaleUnitId($saleUnitId)
                    ->setProductSaleElementsId($productSaleElementsId))
                    ->setQuantity($quantity)
                    ->save($connection) > 0 || $written;
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        return $written;
    }

    /**
     * The DECIMAL columns take a string: a float handed over comes back through PHP's own
     * formatting. A comma typed as the decimal separator is read as one.
     */
    private static function decimal(?string $value, bool $allowNegative): ?string
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ('' === $value) {
            return null;
        }

        if (!is_numeric($value) || (!$allowNegative && (float) $value < 0)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid amount.', $value));
        }

        return number_format((float) $value, 6, '.', '');
    }
}
