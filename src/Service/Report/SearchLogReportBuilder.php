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

namespace BackOfficeDefaultTwigBundle\Service\Report;

use BackOfficeDefaultTwigBundle\DTO\Report\SearchLogReport;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\SearchLogReader;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SearchLogReportBuilder
{
    private const SYNONYMS_ROUTE = 'admin.configuration.search_synonyms.default';

    public function __construct(
        private SearchLogReader $reader,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(int $limit = 50): SearchLogReport
    {
        $availability = $this->reader->availability();

        return new SearchLogReport(
            $availability,
            $this->reader->topZeroResultTerms($limit),
            $this->reader->topSearchedTerms($limit),
            $availability->isReadable() ? $this->urlGenerator->generate(self::SYNONYMS_ROUTE) : null,
        );
    }
}
