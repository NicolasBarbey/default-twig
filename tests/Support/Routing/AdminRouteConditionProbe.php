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

namespace BackOfficeDefaultTwigBundle\Tests\Support\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The service a back-office route condition calls through service(), and the
 * controller of that route.
 *
 * The condition lets the request through only when it asks to be let through,
 * so a test tells a condition that was evaluated from one that was skipped.
 */
final class AdminRouteConditionProbe
{
    public const string ALIAS = 'admin_route_condition_probe';

    public const string BODY = 'Admin route condition evaluated.';

    public function allows(Request $request): bool
    {
        return $request->query->getBoolean('allowed');
    }

    public function __invoke(): Response
    {
        return new Response(self::BODY);
    }
}
