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

namespace BackOfficeDefaultTwigBundle\Service\SaleUnit;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;

/**
 * What an administrator restricted to some sale units cannot do, whatever its profile:
 * manage the administrators, and write what holds for every sale unit (the national
 * prices and stocks). A shop that restricts nobody is never refused.
 */
final readonly class RestrictedAdminGuard
{
    public function __construct(
        private AdminSaleUnitScope $scope,
        private TranslatorInterface $translator,
        private AdminLogger $adminLogger,
    ) {
    }

    public function isRestricted(): bool
    {
        return $this->scope->isRestricted();
    }

    /**
     * A 403 for a restricted administrator, null for any other.
     */
    public function denyIfRestricted(string $resource, string $access): ?Response
    {
        if (!$this->scope->isRestricted()) {
            return null;
        }

        $this->adminLogger->log($resource, $access, 'Refused to an administrator restricted to some sale units');

        return new Response(
            $this->translator->trans('You are restricted to some sale units: this action is not available to you.'),
            Response::HTTP_FORBIDDEN,
        );
    }
}
