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

use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;

final readonly class CurrencyRepository
{
    public function find(int $currencyId): ?Currency
    {
        return $currencyId > 0 ? CurrencyQuery::create()->findPk($currencyId) : null;
    }

    public function findDefault(): ?Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1);
    }
}
