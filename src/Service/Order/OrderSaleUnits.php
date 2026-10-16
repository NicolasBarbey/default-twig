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

use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use Symfony\Contracts\Service\ResetInterface;
use Thelia\Domain\SaleUnit\SaleUnitActivityChecker;
use Thelia\Model\Order;

/**
 * The sale units the order screens show, read once per request and shared by the filter
 * and the rows. They show while the shop sells from sale units: the core keeps that answer
 * in its configuration pool, so a single shop asks the database nothing about them.
 */
final class OrderSaleUnits implements ResetInterface
{
    /** @var array<string, list<array{id: int, code: string, title: string, active: bool}>> */
    private array $optionsByLocale = [];

    public function __construct(
        private readonly SaleUnitActivityChecker $activityChecker,
        private readonly SaleUnitRepository $saleUnits,
    ) {
    }

    /**
     * @return list<array{id: int, code: string, title: string, active: bool}>
     */
    public function options(string $locale): array
    {
        if (!$this->activityChecker->isMultiSaleUnitMode()) {
            return [];
        }

        return $this->optionsByLocale[$locale] ??= $this->saleUnits->findOptions($locale);
    }

    /**
     * @return array<int, string>
     */
    public function titlesById(string $locale): array
    {
        return array_column($this->options($locale), 'title', 'id');
    }

    public function reset(): void
    {
        $this->optionsByLocale = [];
    }

    public function titleOf(Order $order, string $locale): ?string
    {
        $saleUnitId = $order->getSaleUnitId();
        if (null === $saleUnitId) {
            return null;
        }

        return $this->titlesById($locale)[$saleUnitId] ?? null;
    }
}
