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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\UiComponents;

use BackOfficeDefaultTwigBundle\UiComponents\Pagination\Pagination;
use PHPUnit\Framework\TestCase;

/** The link of a page keeps the filters, drops the empty ones and, when asked, brings the reader back to the paginated block. */
final class PaginationLinkParamsTest extends TestCase
{
    public function testALinkWithoutFragmentCarriesThePageAndTheFilledFilters(): void
    {
        $pagination = $this->pagination();

        self::assertSame(['order_id' => 7, 'history_page' => 3], $pagination->linkParams(3));
    }

    public function testALinkWithAFragmentAnchorsTheReloadOnThePaginatedBlock(): void
    {
        $pagination = $this->pagination();
        $pagination->fragment = 'order-history';

        self::assertSame(
            ['order_id' => 7, 'history_page' => 3, '_fragment' => 'order-history'],
            $pagination->linkParams(3),
        );
    }

    public function testAnEmptyFragmentAddsNoAnchor(): void
    {
        $pagination = $this->pagination();
        $pagination->fragment = '';

        self::assertArrayNotHasKey('_fragment', $pagination->linkParams(3));
    }

    private function pagination(): Pagination
    {
        $pagination = new Pagination();
        $pagination->route = 'admin.order.update.view';
        $pagination->currentPage = 1;
        $pagination->lastPage = 4;
        $pagination->routeParams = ['order_id' => 7, 'items_page' => null];
        $pagination->pageParam = 'history_page';

        return $pagination;
    }
}
