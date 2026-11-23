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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;

/**
 * The customer as the lists of the back office show them: the flag of their country before
 * the name, the e-mail underneath, cut when the table is short of room with the full
 * address on hover. One rendering for the lists of customers, orders, returns and
 * subscriptions, so the same person reads the same way everywhere.
 */
final class CustomerCell
{
    /**
     * @param string|null $sheetUrl the customer sheet the name opens, null to leave the name as text
     * @param bool        $compact  the narrower e-mail of the lists where other columns need the room
     */
    public static function html(
        ?string $sheetUrl,
        string $firstname,
        string $lastname,
        string $email,
        ?string $countryIso = null,
        string $countryTitle = '',
        bool $compact = false,
    ): string {
        $name = trim($firstname.' '.$lastname);
        $name = htmlspecialchars($name !== '' ? $name : $email);

        if ($sheetUrl !== null) {
            $name = \sprintf(
                '<a href="%s" class="text-decoration-none" data-testid="bo-customer-name-link">%s</a>',
                htmlspecialchars($sheetUrl),
                $name,
            );
        }

        $email = htmlspecialchars($email);

        return \sprintf(
            '<div class="bo-customer-cell%s"><div class="fw-semibold">%s%s</div><div class="text-muted small text-truncate" title="%s">%s</div></div>',
            $compact ? ' bo-customer-cell--compact' : '',
            CountryFlag::named($countryIso, $countryTitle),
            $name,
            $email,
            $email,
        );
    }
}
