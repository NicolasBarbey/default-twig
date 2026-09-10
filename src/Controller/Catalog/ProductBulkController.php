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

namespace BackOfficeDefaultTwigBundle\Controller\Catalog;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductBulkAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Product;
use Thelia\Model\ProductAssociationType;
use Thelia\Model\ProductAssociationTypeQuery;
use Thelia\Model\ProductQuery;
use Thelia\Tools\TokenProvider;

/**
 * Bulk edits on the catalog list: one selection, one action, applied through the
 * regular product events. Everything is POST + CSRF and reports what happened
 * through a flash message.
 */
#[Route('/admin/products/bulk', name: 'admin.products.bulk.')]
final class ProductBulkController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const LIST_ROUTE = 'admin.products.default';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly ProductBulkAction $bulk,
    ) {
    }

    #[Route('/edit', name: 'edit', methods: ['POST'])]
    public function edit(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }
        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $products = $this->selectedProducts($request);
        if ($products === []) {
            return $this->back($request, 'No product selected.', level: 'warning');
        }

        $locale = $request->getLocale();
        $summary = [];

        $visibility = (string) $request->request->get('visibility', '');
        if ($visibility === 'online' || $visibility === 'offline') {
            $count = $this->bulk->setVisibility($products, $visibility === 'online');
            $summary[] = $visibility === 'online'
                ? $this->translator->trans('%count% products put online', ['%count%' => $count])
                : $this->translator->trans('%count% products taken offline', ['%count%' => $count]);
        }

        $defaultCategoryId = (int) $request->request->get('default_category_id', 0);
        if ($defaultCategoryId > 0) {
            $count = $this->bulk->setDefaultCategory($products, $defaultCategoryId, $locale);
            $summary[] = $this->translator->trans('%count% default categories changed', ['%count%' => $count]);
        }

        if ($request->request->has('template_id')) {
            $rawTemplate = (string) $request->request->get('template_id', '');
            if ($rawTemplate !== '') {
                $templateId = (int) $rawTemplate;
                $count = $this->bulk->setTemplate($products, $templateId > 0 ? $templateId : null);
                $summary[] = $this->translator->trans('%count% product templates changed', ['%count%' => $count]);
            }
        }

        if ($request->request->has('brand_id')) {
            $rawBrand = (string) $request->request->get('brand_id', '');
            if ($rawBrand !== '') {
                $brandId = (int) $rawBrand;
                $count = $this->bulk->setBrand($products, $brandId > 0 ? $brandId : null, $locale);
                $summary[] = $this->translator->trans('%count% brands changed', ['%count%' => $count]);
            }
        }

        $summary = array_merge($summary, $this->applyRelations($request, $products));

        if ($summary === []) {
            return $this->back($request, 'Nothing to apply: pick at least one change.', level: 'warning');
        }

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('Bulk edit on %d products: %s', \count($products), implode(' / ', $summary)),
        );

        return $this->back($request, implode(' · ', $summary), translated: true);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }
        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $products = $this->selectedProducts($request);
        if ($products === []) {
            return $this->back($request, 'No product selected.', level: 'warning');
        }

        $labels = array_map(static fn (Product $product): string => (string) $product->getRef(), $products);
        $count = $this->bulk->delete($products);

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::DELETE,
            \sprintf('Bulk delete of %d products: %s', $count, implode(', ', $labels)),
        );

        return $this->back($request, $this->translator->trans('%count% products deleted', ['%count%' => $count]), translated: true);
    }

    /**
     * @param list<Product> $products
     *
     * @return list<string>
     */
    private function applyRelations(Request $request, array $products): array
    {
        $summary = [];

        $typeCode = (string) $request->request->get('association_type', '');
        $typeCode = '' === $typeCode ? ProductAssociationType::CODE_ACCESSORY : $typeCode;

        $map = [
            'content' => [
                'add' => fn (array $ids): int => $this->bulk->addContents($products, $ids),
                'remove' => fn (array $ids): int => $this->bulk->removeContents($products, $ids),
                'addLabel' => '%count% associated contents added',
                'removeLabel' => '%count% associated contents removed',
            ],
            'association' => [
                'add' => fn (array $ids): int => $this->bulk->addAssociations($products, $ids, $typeCode),
                'remove' => fn (array $ids): int => $this->bulk->removeAssociations($products, $ids, $typeCode),
                'addLabel' => '%count% relations added to %type%',
                'removeLabel' => '%count% relations removed from %type%',
                'labelParameters' => ['%type%' => $this->associationTypeLabel($typeCode, $request->getLocale())],
            ],
            'category' => [
                'add' => fn (array $ids): int => $this->bulk->addCategories($products, $ids),
                'remove' => fn (array $ids): int => $this->bulk->removeCategories($products, $ids),
                'addLabel' => '%count% additional categories added',
                'removeLabel' => '%count% additional categories removed',
            ],
        ];

        foreach ($map as $kind => $handlers) {
            foreach (['add', 'remove'] as $mode) {
                $ids = $this->intList($request->request->all($kind.'_'.$mode.'_ids'));

                // The accessory field names the screens used before types existed.
                if ($ids === [] && 'association' === $kind) {
                    $ids = $this->intList($request->request->all('accessory_'.$mode.'_ids'));
                }

                if ($ids === []) {
                    continue;
                }

                $count = $handlers[$mode]($ids);
                $summary[] = $this->translator->trans(
                    $handlers[$mode.'Label'],
                    ['%count%' => $count] + ($handlers['labelParameters'] ?? []),
                );
            }
        }

        return $summary;
    }

    private function associationTypeLabel(string $typeCode, string $locale): string
    {
        $type = ProductAssociationTypeQuery::create()->filterByCode($typeCode)->findOne();

        if (null === $type) {
            return $typeCode;
        }

        $type->setLocale($locale);

        return (string) $type->getTitle();
    }

    /**
     * @return list<Product>
     */
    private function selectedProducts(Request $request): array
    {
        $ids = $this->intList($request->request->all('product_ids'));
        if ($ids === []) {
            return [];
        }

        $products = ProductQuery::create()
            ->filterById($ids, Criteria::IN)
            ->find();

        return array_values(iterator_to_array($products));
    }

    /**
     * @param array<int|string, mixed> $raw
     *
     * @return list<int>
     */
    private function intList(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function back(Request $request, string $message, string $level = 'success', bool $translated = false): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session !== null && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add(
                $level,
                $translated ? $message : $this->translator->trans($message),
            );
        }

        $referer = (string) $request->headers->get('referer', '');

        return new RedirectResponse($referer !== '' ? $referer : $this->urls->generate(self::LIST_ROUTE));
    }
}
