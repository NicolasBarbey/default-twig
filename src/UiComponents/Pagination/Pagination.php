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

namespace BackOfficeDefaultTwigBundle\UiComponents\Pagination;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoPagination', template: '@BackOfficeDefaultTwig/components/Pagination/Pagination.html.twig')]
final class Pagination
{
    public string $route;

    public int $currentPage;

    public int $lastPage;

    /** @var array<string, scalar|null> */
    public array $routeParams = [];

    public string $pageParam = 'page';

    public int $window = 2;

    public string $align = 'center';

    public ?string $ariaLabel = null;

    public ?string $testid = null;

    public ?string $fragment = null;

    /**
     * Page numbers to display, with null marking an ellipsis. Always keeps the first and last page.
     *
     * @return list<int|null>
     */
    public function getPages(): array
    {
        $start = max(1, $this->currentPage - $this->window);
        $end = min($this->lastPage, $this->currentPage + $this->window);

        $pages = [];

        if ($start > 1) {
            $pages[] = 1;
            if ($start > 2) {
                $pages[] = null;
            }
        }

        for ($page = $start; $page <= $end; ++$page) {
            $pages[] = $page;
        }

        if ($end < $this->lastPage) {
            if ($end < $this->lastPage - 1) {
                $pages[] = null;
            }
            $pages[] = $this->lastPage;
        }

        return $pages;
    }

    /**
     * True when collapsing to a window of 1 below md hides the page right after the first
     * page without a native ellipsis to signal the gap. getPages() only emits a null before
     * its own window (e.g. page 4 of 21, window 2: [1, 2, 3, 4, 5, 6, null, 21]) - collapsing
     * "2" away in CSS then leaves "1  3" with nothing marking the skipped page.
     */
    public function hasCollapsedLeadingGap(): bool
    {
        $start = max(1, $this->currentPage - $this->window);
        $mobileStart = max(1, $this->currentPage - max(0, $this->window - 1));

        return $start <= 2 && $mobileStart > 2;
    }

    /**
     * Symmetric trailing case: the page right before the last page collapses away below md
     * without a native ellipsis.
     */
    public function hasCollapsedTrailingGap(): bool
    {
        $end = min($this->lastPage, $this->currentPage + $this->window);
        $mobileEnd = min($this->lastPage, $this->currentPage + max(0, $this->window - 1));

        return $end >= $this->lastPage - 1 && $mobileEnd < $this->lastPage - 1;
    }

    /**
     * Route parameters for a given page, dropping empty filters so the URL stays clean.
     * The fragment, when set, brings the browser back to the paginated block after the reload.
     *
     * @return array<string, scalar>
     */
    public function linkParams(int $page): array
    {
        $params = array_filter(
            $this->routeParams,
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );

        $params[$this->pageParam] = $page;

        if (null !== $this->fragment && '' !== $this->fragment) {
            $params['_fragment'] = $this->fragment;
        }

        return $params;
    }
}
