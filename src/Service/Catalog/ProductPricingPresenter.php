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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\CurrencyQuery;

/**
 * Renders the price, sale price and stock cells of the catalog list: untaxed on
 * top, taxed below, and a stock badge that turns red when nothing is left.
 */
final class ProductPricingPresenter
{
    private ?string $currencyCode = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MoneyFormatter $money,
    ) {
    }

    public function price(ProductPricingSnapshot $snapshot, string $locale): string
    {
        if ($snapshot->price <= 0.0) {
            return '<span class="text-muted">-</span>';
        }

        return $this->amountPair($snapshot->price, $snapshot->taxedPrice, $locale);
    }

    public function promoPrice(ProductPricingSnapshot $snapshot, string $locale): string
    {
        if ($snapshot->promoPrice <= 0.0) {
            return '<span class="text-muted">-</span>';
        }

        $pair = $this->amountPair($snapshot->promoPrice, $snapshot->taxedPromoPrice, $locale);
        if (!$snapshot->onSale) {
            // Value is set but the sale is off: show it muted so the list does not
            // suggest customers are being charged that amount.
            return \sprintf(
                '<span class="text-muted" data-bs-toggle="tooltip" title="%s">%s</span>',
                htmlspecialchars($this->translator->trans('Sale is not active on this product')),
                $pair,
            );
        }

        return $pair;
    }

    public function stock(ProductPricingSnapshot $snapshot): string
    {
        $quantity = $this->formatQuantity($snapshot->quantity);
        $class = $snapshot->quantity <= 0.0 ? 'text-bg-danger-subtle text-danger-emphasis' : 'text-bg-light';

        $tooltip = $snapshot->saleElementCount > 1
            ? $this->translator->trans('Total stock over %count% combinations', ['%count%' => $snapshot->saleElementCount])
            : '';

        return \sprintf(
            '<span class="badge %s bo-product-stock"%s>%s</span>',
            $class,
            $tooltip !== '' ? ' data-bs-toggle="tooltip" title="'.htmlspecialchars($tooltip).'"' : '',
            htmlspecialchars($quantity),
        );
    }

    private function amountPair(float $untaxed, float $taxed, string $locale): string
    {
        // The tax wording only shows from md up: on a phone the amounts alone keep the
        // cell narrow enough to stay clear of the pinned actions column.
        return \sprintf(
            '<div class="bo-product-price"><div class="fw-semibold">%s<span class="d-none d-md-inline"> %s</span></div><div class="text-muted small">%s<span class="d-none d-md-inline"> %s</span></div></div>',
            htmlspecialchars($this->money->format($untaxed, $locale, $this->currencyCode())),
            htmlspecialchars($this->translator->trans('excl. tax')),
            htmlspecialchars($this->money->format($taxed, $locale, $this->currencyCode())),
            htmlspecialchars($this->translator->trans('incl. tax')),
        );
    }

    private function formatQuantity(float $quantity): string
    {
        return $quantity === floor($quantity)
            ? number_format($quantity, 0, ',', ' ')
            : number_format($quantity, 2, ',', ' ');
    }

    private function currencyCode(): ?string
    {
        if ($this->currencyCode !== null) {
            return $this->currencyCode;
        }

        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? CurrencyQuery::create()->findOne();

        return $this->currencyCode = $currency !== null ? (string) $currency->getCode() : null;
    }
}
