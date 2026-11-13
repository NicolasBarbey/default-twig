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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use Thelia\Domain\Order\Sql\OrderAmountSql;
use Thelia\Domain\Pricing\OrderLineRounding;
use Thelia\Model\ConfigQuery;

/**
 * How a line total is built from a unit amount and a quantity, for one order.
 *
 * The core decides this per order, and the decision is durable on purpose: reading
 * an invoice back under a newer rule would restate an amount the customer has
 * already paid. Two configuration pivots freeze the orders that came before a
 * change of rule, and the rest follow the rule the shop runs today.
 *
 * The pivots and the mode are read raw through ConfigQuery::read() rather than
 * through ConfigQuery::getOrderRoundingMode(): that method answers for one order
 * at a time, so it cannot be consulted from SQL, and reading raw also keeps this
 * template working against a core that predates thelia/thelia#3801, where
 * order_rounding_mode is simply absent and the historical rule applies.
 */
enum OrderRoundingRule
{
    /**
     * Orders placed before Thelia 2.4 were totalled without any rounding at all.
     * Order::getTotalAmountLegacy() still reads them that way.
     */
    case Legacy;

    /**
     * Every unit amount is rounded to the cent before being multiplied by the
     * quantity. Historical Thelia behaviour, and still the default.
     */
    case SumOfRoundings;

    /**
     * The unit amount multiplies the quantity at full precision and only the line
     * total is rounded. What a shop selling by weight or by volume needs: a price
     * stored per gram is nothing once cut to the cent.
     */
    case RoundingOfSums;

    /**
     * Mirrors ConfigQuery::ROUNDING_MODE_* (thelia/thelia#3801).
     */
    private const MODE_SUM_OF_ROUNDINGS = 1;
    private const MODE_ROUNDING_OF_SUMS = 2;

    public static function forOrder(int $orderId): self
    {
        if ($orderId > 0 && $orderId <= self::legacyPivot()) {
            return self::Legacy;
        }

        if ($orderId > 0 && $orderId <= self::sumOfRoundingsPivot()) {
            return self::SumOfRoundings;
        }

        return self::shopRule();
    }

    /**
     * The rule the shop runs today, which every order past both pivots follows.
     */
    public static function shopRule(): self
    {
        return self::MODE_ROUNDING_OF_SUMS === (int) ConfigQuery::read('order_rounding_mode', self::MODE_SUM_OF_ROUNDINGS)
            ? self::RoundingOfSums
            : self::SumOfRoundings;
    }

    /**
     * Id of the last order frozen on the pre-2.4 rule, 0 when the shop has none.
     * Written once by the 2.4 upgrade, so it exists on cores without #3801 too.
     */
    public static function legacyPivot(): int
    {
        return (int) ConfigQuery::read('last_legacy_rounding_order_id', 0);
    }

    /**
     * Id of the last order frozen on the historical rule when the shop switched to
     * rounding of sums, 0 when it never switched.
     */
    public static function sumOfRoundingsPivot(): int
    {
        return (int) ConfigQuery::read('last_sum_of_roundings_order_id', 0);
    }

    /**
     * Id of the last order whose line totals were rounded on the floating point figure under
     * the rounding of sums (1.25 x 14.82 kept as 18.52), 0 when the shop has none. Written
     * once by the 3.4 upgrade (ConfigQuery::isOrderWithFloatLineRounding()), and 0 on a shop
     * installed in 3.4 or later.
     *
     * It always comes after the pivot of the text promotional price (3.3 upgrade), so the
     * orders the core reads with a text promotional price are read on the floating point
     * figure here too, which is what the text reading amounts to.
     */
    public static function floatLineRoundingPivot(): int
    {
        return (int) ConfigQuery::read('last_float_line_rounding_order_id', 0);
    }

    /**
     * Whether the core holds the rounding rule of an order line (Thelia\Domain\Pricing\OrderLineRounding),
     * which the order total, the export, the sales journal and the refunds of the core share.
     * The template then reads it; on an older core it keeps its own copy.
     */
    public static function coreHasTheRule(): bool
    {
        return class_exists(OrderLineRounding::class);
    }

    /**
     * Whether the core writes the amounts of an order in SQL for the queries that total many
     * orders (Thelia\Domain\Order\Sql\OrderAmountSql), in a form that takes no parameter.
     * The order list then reads it; on an older core it keeps its own copy.
     */
    public static function coreTotalsOrders(): bool
    {
        return method_exists(OrderAmountSql::class, 'withRoundingPivotsInlined');
    }

    /**
     * The three figures of one line of the given order, each tax of the line given apart:
     * under the sum of roundings, the order rounds each tax to the cent before adding them
     * (a line of 10.00 with two taxes of 0.125 is charged 10.26, not 10.25).
     *
     * @param list<float> $unitTaxes
     *
     * @return array{ht: float, tax: float, ttc: float}
     */
    public static function lineTotalsOfOrder(int $orderId, float $unitPriceUntaxed, array $unitTaxes, float $quantity): array
    {
        $rule = self::forOrder($orderId);

        // class_exists() rather than coreHasTheRule(): static analysis reads the guard only here.
        if (!class_exists(OrderLineRounding::class)) {
            return $rule->lineTotals($unitPriceUntaxed, array_sum($unitTaxes), $quantity);
        }

        $rounding = OrderLineRounding::ofOrder($orderId);
        $untaxed = $rounding->lineTotal($quantity, $unitPriceUntaxed);
        $taxed = $rounding->lineTotal($quantity, $unitPriceUntaxed, $unitTaxes);

        if (self::Legacy === $rule) {
            return ['ht' => $untaxed, 'tax' => $taxed - $untaxed, 'ttc' => $taxed];
        }

        $untaxed = self::toTheCent($untaxed);
        $taxed = self::toTheCent($taxed);

        return ['ht' => $untaxed, 'tax' => self::toTheCent($taxed - $untaxed), 'ttc' => $taxed];
    }

    /**
     * The three figures of one order line: `ht` and `ttc` are the unit prices times the
     * quantity, and `tax` is the gap between the two rather than a rounding of its own.
     * The legacy rule leaves them unrounded; every other rule rounds each of them to the
     * cent (the sum-of-roundings rule rounds the unit prices first).
     *
     * Outside the legacy rule the order is charged in cents, rounded half up by
     * round(): the line is rounded the same way before it is shown. The money
     * formatter would otherwise round it half to even, and a line sold by measure
     * (1.25 at 14.82, 18.525) would read a cent below the total it makes.
     *
     * Outside the legacy rule the lines do not always add up to the order:
     * Order::getTotalAmount() rounds the sum of the unrounded lines, here each line is
     * rounded first. Two lines of
     * 18.525 show 18.53 + 18.53 = 37.06 for an order total of 37.05. The one cent
     * of difference is accepted.
     *
     * @return array{ht: float, tax: float, ttc: float}
     */
    public function lineTotals(float $unitPriceUntaxed, float $unitTax, float $quantity): array
    {
        if (self::Legacy === $this) {
            $untaxed = $unitPriceUntaxed * $quantity;
            $tax = $unitTax * $quantity;

            return ['ht' => $untaxed, 'tax' => $tax, 'ttc' => $untaxed + $tax];
        }

        if (self::SumOfRoundings === $this) {
            $unitPriceUntaxed = self::toTheCent($unitPriceUntaxed);
            $unitTax = self::toTheCent($unitTax);
        }

        $untaxed = self::toTheCent($unitPriceUntaxed * $quantity);
        $taxed = self::toTheCent(($unitPriceUntaxed + $unitTax) * $quantity);

        return ['ht' => $untaxed, 'tax' => self::toTheCent($taxed - $untaxed), 'ttc' => $taxed];
    }

    /**
     * An amount rounded half up to the cent the way the core rounds it
     * (Thelia\Domain\Pricing\MoneyRounding::toTheCent()), copied here because the template
     * must keep working against a published core that does not have it yet: brought back
     * to six decimals first, so that 0.7 kg at 0.75 (0.52499999… as a float) shows the 0.53
     * the order charged on PHP 8.4 as on PHP 8.3, which pre-rounded it.
     */
    public static function toTheCent(float $amount): float
    {
        return round(round($amount, 6), 2);
    }
}
