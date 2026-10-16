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

namespace BackOfficeDefaultTwigBundle\Service\SaleUnit;

use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\SaleUnit;

final readonly class SaleUnitListPresenter
{
    public const SORTABLE_FIELDS = ['id', 'code', 'title', 'url', 'position'];

    public function __construct(
        private SaleUnitRepository $saleUnits,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $locale, string $sortField, string $sortDirection): array
    {
        $rows = [];

        foreach ($this->saleUnits->findSorted($locale, $sortField, $sortDirection) as $saleUnit) {
            $rows[] = $this->row($saleUnit, $locale);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SaleUnit $saleUnit, string $locale): array
    {
        $id = (int) $saleUnit->getId();
        $title = SaleUnitRepository::titleOf($saleUnit, $locale);

        return [
            'id' => $id,
            'code' => (string) $saleUnit->getCode(),
            'title' => $title,
            'url' => (string) $saleUnit->getUrl(),
            'active' => (bool) $saleUnit->getActive(),
            'toggle_active_url' => $this->urls->generate('admin.sale-unit.toggle-active', ['sale_unit_id' => $id]),
            'position' => (int) $saleUnit->getPosition(),
            '_actions' => [
                new RowAction(
                    kind: 'edit',
                    label: $this->translator->trans('Edit'),
                    href: $this->urls->generate('admin.sale-unit.update', ['sale_unit_id' => $id]),
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: AdminResources::SALE_UNIT,
                ),
                new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete'),
                    modalTarget: '#sale-unit-delete-modal',
                    grantedAttribute: AccessManager::DELETE,
                    grantedSubject: AdminResources::SALE_UNIT,
                    dataAttributes: ['sale-unit-id' => $id, 'sale-unit-label' => $title],
                ),
            ],
        ];
    }
}
