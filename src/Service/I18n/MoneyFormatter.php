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

use Thelia\Model\Currency;

/**
 * Formats an amount for the PHP presenters that build their own cells, the way
 * Twig's format_currency does: ISO code of the currency, locale of the admin.
 */
final readonly class MoneyFormatter
{
    /**
     * @param string|null $currencyCode ISO 4217 code of the amount's currency; the shop's default currency when null
     */
    public function format(float $amount, string $locale, ?string $currencyCode = null): string
    {
        $code = $currencyCode ?? (string) Currency::getDefaultCurrency()->getCode();

        return (new \NumberFormatter($locale, \NumberFormatter::CURRENCY))->formatCurrency($amount, $code);
    }
}
