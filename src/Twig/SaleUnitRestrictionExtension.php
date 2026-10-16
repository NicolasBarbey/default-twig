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

namespace BackOfficeDefaultTwigBundle\Twig;

use BackOfficeDefaultTwigBundle\Service\SaleUnit\RestrictedAdminGuard;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `sale_unit_restricted()`: whether the administrator of the request is restricted to some
 * sale units, to leave out what such an administrator cannot use.
 */
final class SaleUnitRestrictionExtension extends AbstractExtension
{
    public function __construct(private readonly RestrictedAdminGuard $guard)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sale_unit_restricted', $this->guard->isRestricted(...)),
        ];
    }
}
