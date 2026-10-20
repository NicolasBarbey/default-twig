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

namespace BackOfficeDefaultTwigBundle\Service\OrderReturn;

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnStatus;

/**
 * Maps an OrderReturn model to the DataTable row shape used by
 * `order-return/list.html.twig`.
 */
final readonly class OrderReturnListRowPresenter
{
    private const DETAIL_ROUTE = 'admin.order-return.detail';
    private const RECEPTION_ROUTE = 'admin.order-return.reception.view';
    private const ORDER_ROUTE = 'admin.order.update.view';
    private const FALLBACK_STATUS_COLOR = '#6c757d';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private OrderReturnRepository $returns,
        private MoneyFormatter $money,
    ) {
    }

    /**
     * A whole page of rows, reading the per-page figures once instead of once per
     * row: the number of lines of every return of the page comes back in a single
     * grouped query.
     *
     * @param iterable<OrderReturn> $returns
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(iterable $returns, string $locale): array
    {
        $page = [];
        $returnIds = [];
        foreach ($returns as $orderReturn) {
            $page[] = $orderReturn;
            $returnIds[] = (int) $orderReturn->getId();
        }

        if ($page === []) {
            return [];
        }

        $lineCounts = $this->returns->countLinesByReturn($returnIds);

        $rows = [];
        foreach ($page as $orderReturn) {
            $rows[] = $this->present($orderReturn, $locale, $lineCounts);
        }

        return $rows;
    }

    /**
     * @param array<int, int> $lineCounts number of lines per return id, when the caller has them
     *
     * @return array<string, mixed>
     */
    public function present(OrderReturn $orderReturn, string $locale, array $lineCounts = []): array
    {
        $returnId = (int) $orderReturn->getId();
        // status_id is a required column behind a RESTRICT foreign key: a return
        // always carries its status.
        $status = $orderReturn->getOrderReturnStatus();
        $status->setLocale($locale);
        $effectiveCode = $status->getEffectiveCode();

        return [
            'id' => $returnId,
            'ref' => (string) $orderReturn->getRef(),
            'ref_html' => $this->renderRef($orderReturn, $returnId),
            'order_html' => $this->renderOrder($orderReturn),
            'customer_html' => $this->renderCustomer($orderReturn),
            'lines_html' => $this->renderLines($returnId, $lineCounts),
            'status' => (string) $status->getTitle(),
            'status_code' => $effectiveCode,
            'status_color' => (string) ($status->getColor() ?: self::FALLBACK_STATUS_COLOR),
            'refund_amount' => $this->formatAmount($orderReturn, $locale),
            'date_html' => $this->renderDate($orderReturn),
            '_actions' => $this->buildActions($returnId, $effectiveCode),
        ];
    }

    private function renderRef(OrderReturn $orderReturn, int $returnId): string
    {
        $href = $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $returnId]);
        $ref = (string) $orderReturn->getRef();

        $link = \sprintf(
            '<a href="%s" class="fw-semibold text-decoration-none" data-testid="bo-order-return-ref-link">%s</a>',
            htmlspecialchars($href, \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($ref !== '' ? $ref : '#'.$returnId, \ENT_QUOTES | \ENT_HTML5),
        );

        if (!$orderReturn->getCreatedByAdmin()) {
            return $link;
        }

        return $link.\sprintf(
            '<div class="text-muted small"><i class="bi bi-shop me-1" aria-hidden="true"></i>%s</div>',
            htmlspecialchars($this->translator->trans('Opened by the merchant'), \ENT_QUOTES | \ENT_HTML5),
        );
    }

    private function renderOrder(OrderReturn $orderReturn): string
    {
        // order_id is a required column behind a RESTRICT foreign key.
        $order = $orderReturn->getOrder();

        return \sprintf(
            '<a href="%s" class="text-decoration-none">%s</a>',
            htmlspecialchars($this->urls->generate(self::ORDER_ROUTE, ['order_id' => (int) $order->getId()]), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars((string) $order->getRef(), \ENT_QUOTES | \ENT_HTML5),
        );
    }

    private function renderCustomer(OrderReturn $orderReturn): string
    {
        // customer_id is a required column behind a RESTRICT foreign key.
        $customer = $orderReturn->getCustomer();

        return \sprintf(
            '<div class="bo-order-customer"><div class="fw-semibold">%s %s</div><div class="text-muted small text-truncate">%s</div></div>',
            htmlspecialchars((string) $customer->getFirstname(), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars((string) $customer->getLastname(), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars((string) $customer->getEmail(), \ENT_QUOTES | \ENT_HTML5),
        );
    }

    /**
     * @param array<int, int> $lineCounts
     */
    private function renderLines(int $returnId, array $lineCounts): string
    {
        $count = $lineCounts[$returnId] ?? 0;
        $label = $this->translator->trans(
            $count === 1 ? '%count% line' : '%count% lines',
            ['%count%' => $count],
        );

        return \sprintf(
            '<span class="badge text-bg-light"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>%s</span>',
            htmlspecialchars($label, \ENT_QUOTES | \ENT_HTML5),
        );
    }

    private function renderDate(OrderReturn $orderReturn): string
    {
        $createdAt = $orderReturn->getCreatedAt();

        if ($createdAt === null) {
            return '<span class="text-muted">-</span>';
        }

        $display = $createdAt->format('d/m/Y H:i');

        return \sprintf(
            '<time datetime="%s" title="%s">%s</time>',
            htmlspecialchars($createdAt->format(\DateTimeInterface::ATOM), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($display, \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($display, \ENT_QUOTES | \ENT_HTML5),
        );
    }

    private function formatAmount(OrderReturn $orderReturn, string $locale): string
    {
        return $this->money->format(
            (float) $orderReturn->getRefundAmount(),
            $locale,
            $orderReturn->getOrder()->getCurrency()->getCode(),
        );
    }

    /**
     * @return list<RowAction>
     */
    private function buildActions(int $returnId, string $effectiveCode): array
    {
        $actions = [
            new RowAction(
                kind: 'view',
                label: $this->translator->trans('View return'),
                href: $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $returnId]),
                grantedAttribute: AccessManager::VIEW,
                grantedSubject: AdminResources::ORDER_RETURN,
            ),
        ];

        if ($effectiveCode === OrderReturnStatus::CODE_ACCEPTED) {
            $actions[] = new RowAction(
                kind: 'receive',
                label: $this->translator->trans('Record the reception'),
                href: $this->urls->generate(self::RECEPTION_ROUTE, ['order_return_id' => $returnId]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::ORDER_RETURN,
                inlineFrom: 'md',
            );
        }

        return $actions;
    }
}
