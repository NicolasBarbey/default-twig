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

namespace BackOfficeDefaultTwigBundle\Service\Pdf;

use Symfony\Component\HttpFoundation\Response;
use Thelia\Domain\Order\Exception\PickingSlipException;
use Thelia\Domain\Order\Service\PickingSlipPdfService;

/**
 * Serves the picking slip of the core as a response. The orders given have already been
 * narrowed to the ones the administrator reaches: the core service does not look at rights.
 */
final readonly class OrderPickingSlipRenderer
{
    public function __construct(
        private PickingSlipPdfService $pickingSlip,
    ) {
    }

    /**
     * The most orders a slip holds, as the core service reads it.
     */
    public function limit(): int
    {
        return $this->pickingSlip->maxOrders();
    }

    /**
     * Whether the active PDF theme has a preparation document: without one, no button is shown.
     */
    public function isAvailable(): bool
    {
        return $this->pickingSlip->isAvailable();
    }

    /**
     * @param list<int> $orderIds
     *
     * @throws PickingSlipException when the slip cannot be produced
     */
    public function respond(array $orderIds, bool $browser): Response
    {
        $slip = $this->pickingSlip->render($orderIds);

        $response = new Response($slip->content);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            \sprintf('%s; filename="%s"', $browser ? 'inline' : 'attachment', $slip->fileName),
        );

        return $response;
    }
}
