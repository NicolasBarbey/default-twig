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

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;

/**
 * What the report screens share: the permission check, the parameters of a period and of the
 * orders counted, and the export form of a report.
 */
final readonly class ReportScreen
{
    public const SCOPE_PAID = 'paid';

    public function __construct(
        private DataTransferRepository $dataTransfers,
        private SecurityContext $securityContext,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function canView(string $resource): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [$resource], [], [AccessManager::VIEW]);
    }

    /**
     * Every order is counted unless the address asks for the paid ones only.
     *
     * @return array<string, string>
     */
    public static function scopeParameters(bool $allOrders): array
    {
        return $allOrders ? [] : ['scope' => self::SCOPE_PAID];
    }

    /**
     * @return array<string, string>
     */
    public static function periodParameters(DateRange $range): array
    {
        if (DateRange::PRESET_CUSTOM === $range->preset) {
            return ['period' => DateRange::PRESET_CUSTOM, 'from' => $range->from->format('Y-m-d'), 'to' => $range->to->format('Y-m-d')];
        }

        return ['period' => $range->preset];
    }

    /**
     * The export form of a report over the given days, in the language of the reader (else the
     * first language of the shop), for an administrator allowed to export.
     *
     * @return array{url: string, from: string, to: string, languageId: int}|null
     */
    public function export(string $ref, \DateTimeImmutable $from, \DateTimeImmutable $to, string $locale): ?array
    {
        if (!$this->canView(AdminResources::EXPORT)) {
            return null;
        }

        $exportId = $this->dataTransfers->findExportIdByRef($ref);
        if (null === $exportId) {
            return null;
        }

        $languages = $this->dataTransfers->languages();
        $language = null;
        foreach ($languages as $candidate) {
            if ($candidate->getLocale() === $locale) {
                $language = $candidate;
                break;
            }
        }
        $language ??= $languages[0] ?? null;
        if (null === $language) {
            return null;
        }

        return [
            'url' => $this->urls->generate('export.process', ['id' => $exportId]),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'languageId' => (int) $language->getId(),
        ];
    }
}
