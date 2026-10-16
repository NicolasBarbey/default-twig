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

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `sidebar_order_counts(locale)` so the back-office sidebar can
 * render the small badges next to the Orders sub-menu, mirroring the Smarty
 * `{loop type="order-status"}` pattern. Custom statuses created by
 * integrators are picked up automatically.
 */
final class SidebarCountersExtension extends AbstractExtension implements ResetInterface
{
    /** @var array{locale: string, data: array{total: int, statuses: list<array{id: int, code: string, title: string, color: string, count: int}>}}|null */
    private ?array $cached = null;

    public function __construct(private readonly OrderRepository $orderRepository)
    {
    }

    /**
     * The counts are those of the administrator of the request: a process that serves
     * another one next must not answer with them.
     */
    public function reset(): void
    {
        $this->cached = null;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sidebar_order_counts', $this->orderCounts(...)),
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     statuses: list<array{id: int, code: string, title: string, color: string, count: int}>
     * }
     */
    public function orderCounts(string $locale = 'en_US'): array
    {
        if ($this->cached !== null && $this->cached['locale'] === $locale) {
            return $this->cached['data'];
        }

        $data = [
            'total' => $this->orderRepository->countAll(),
            'statuses' => $this->orderRepository->findStatusesWithCounts($locale),
        ];

        $this->cached = ['locale' => $locale, 'data' => $data];

        return $data;
    }
}
