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

use Propel\Runtime\ActiveQuery\ModelCriteria;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderReturn;

/**
 * Which orders the administrator of the request reaches. An administrator restricted to
 * some sale units reaches the orders of those units only, national orders excluded; any
 * other administrator reaches them all, and a shop without sale units asks nothing.
 */
final readonly class OrderAccess
{
    public function __construct(
        private AdminSaleUnitScope $scope,
    ) {
    }

    public function reaches(Order $order): bool
    {
        $saleUnitId = $order->getSaleUnitId();

        return $this->scope->allows(null === $saleUnitId ? null : (int) $saleUnitId);
    }

    /**
     * An order of another unit does not exist for the administrator.
     */
    public function assertReaches(Order $order): void
    {
        if (!$this->reaches($order)) {
            throw new NotFoundHttpException(\sprintf('Order %d not found', (int) $order->getId()));
        }
    }

    /**
     * A return belongs to the unit of its order. A shop that restricts nobody never loads the order.
     */
    public function assertReachesReturn(OrderReturn $orderReturn): void
    {
        if (!$this->scope->isRestricted()) {
            return;
        }

        $this->assertReaches($orderReturn->getOrder());
    }

    /**
     * Narrows a query on a table holding an order id to the orders reached, by a sub-select
     * on the orders, so the query keeps its own joins.
     */
    public function restrictByOrderId(ModelCriteria $query, string $orderIdColumn): void
    {
        $where = $this->sqlWhere();

        if ('' !== $where) {
            $query->where($orderIdColumn.' IN (SELECT `order`.id FROM `order`'.$where.')');
        }
    }

    public function restrict(ModelCriteria $query, string $saleUnitColumn = OrderTableMap::COL_SALE_UNIT_ID): void
    {
        $this->scope->restrictQuery($query, $saleUnitColumn);
    }

    /**
     * The same restriction for a query written in SQL, as a condition to add with AND:
     * empty when nothing is restricted. The ids are integers read back from the database.
     *
     * @param string $saleUnitColumn a column of the SQL statement, quoted when it needs it
     */
    public function sqlCondition(string $saleUnitColumn = OrderTableMap::COL_SALE_UNIT_ID): string
    {
        $allowed = $this->scope->allowedSaleUnitIds();

        if (null === $allowed) {
            return '';
        }

        return ' AND '.$saleUnitColumn.' IN ('.implode(', ', array_map('intval', $allowed)).')';
    }

    /**
     * The restriction as a WHERE clause of a statement that has none: empty when nothing is restricted.
     */
    public function sqlWhere(string $saleUnitColumn = OrderTableMap::COL_SALE_UNIT_ID): string
    {
        $condition = $this->sqlCondition($saleUnitColumn);

        return '' === $condition ? '' : ' WHERE '.substr($condition, \strlen(' AND '));
    }
}
