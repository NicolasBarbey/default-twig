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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Propel\Runtime\Propel;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Moves a combination to a position of the combinations tab. The combinations are numbered 1 to n in
 * the displayed order first: the core only shifts positions between the old and the new one, so it moves
 * nothing among ties (a Thelia 2 migration). The core's move rolls back any exception silently
 * (changeAbsolutePosition()): the stored position is read again, and the numbering rolled back with it.
 */
final readonly class CombinationPositionMover
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private CombinationDisplayOrder $displayOrder = new CombinationDisplayOrder(),
    ) {
    }

    /**
     * @return bool false when the combination was not moved, its product left as it was
     */
    public function move(ProductSaleElements $combination, int $position): bool
    {
        $product = $combination->getProduct();
        $connection = Propel::getWriteConnection(ProductSaleElementsTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            // Two moves of the same product, from two tabs, wait for each other.
            ProductSaleElementsQuery::create()
                ->filterByProductId((int) $product->getId())
                ->orderById()
                ->select('Id')
                ->lockForUpdate()
                ->find($connection);

            $ordered = $this->displayOrder->of($product, $connection);
            if ($position < 1 || $position > \count($ordered)) {
                $connection->rollBack();

                return false;
            }

            foreach ($ordered as $index => $sibling) {
                if ((int) $sibling->getPosition() !== $index + 1) {
                    $sibling->setPosition($index + 1)->save($connection);
                }
            }

            $this->dispatcher->dispatch(
                new UpdatePositionEvent((int) $combination->getId(), UpdatePositionEvent::POSITION_ABSOLUTE, $position),
                TheliaEvents::PRODUCT_PRODUCT_SALE_ELEMENT_UPDATE_POSITION,
            );

            $stored = ProductSaleElementsQuery::create()
                ->filterById((int) $combination->getId())
                ->select('Position')
                ->findOne($connection);

            if ((int) $stored !== $position) {
                $connection->rollBack();

                return false;
            }

            $connection->commit();

            return true;
        } catch (\Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $failure;
        }
    }
}
