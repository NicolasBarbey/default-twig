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

use BackOfficeDefaultTwigBundle\Repository\CurrencyRepository;
use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Product\SaleUnitValuesWriter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Tools\TokenProvider;

/**
 * Saves, from the combinations tab of a product, the prices and stocks one sale unit keeps
 * of its combinations. Managing what a unit owns of the catalog is a product write and a
 * sale unit write at once: both rights are required.
 */
final class ProductSaleUnitValuesController
{
    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly SaleUnitRepository $saleUnits,
        private readonly CurrencyRepository $currencies,
        private readonly SaleUnitValuesWriter $writer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly AdminSaleUnitScope $scope,
    ) {
    }

    #[Route('/admin/product/sale-unit-values/save', name: 'admin.product.sale-unit-values.save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check([AdminResources::PRODUCT, AdminResources::SALE_UNIT], [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $request->request;
        $productId = (int) $form->get('product_id', 0);
        $currency = $this->currencies->find((int) $form->get('currency_id', 0));
        $saleUnit = $this->saleUnits->find((int) $form->get('sale_unit_id', 0));

        if (null === $saleUnit || null === $currency || $productId <= 0) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        // A unit the administrator is not restricted to does not exist for it: nothing is written on it.
        if (!$this->scope->allows((int) $saleUnit->getId())) {
            throw new NotFoundHttpException(\sprintf('Sale unit %d not found', (int) $saleUnit->getId()));
        }

        $redirect = new RedirectResponse($this->urls->generate('admin.products.update', [
            'product_id' => $productId,
            'current_tab' => 'pse',
            'sale_unit_id' => $saleUnit->getId(),
            'combinations_currency_id' => $currency->getId(),
        ]));

        try {
            $this->tokens->checkToken((string) $form->get('_token', ''));
        } catch (TokenAuthenticationException) {
            $this->flash($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $redirect;
        }

        $prices = $form->all('price');
        $promoPrices = $form->all('promo_price');
        $quantities = $form->all('quantity');
        $refused = [];
        $written = false;

        // Only the combinations of this product: an id posted for another one is ignored.
        foreach (ProductSaleElementsQuery::create()->filterByProductId($productId)->find() as $pse) {
            $pseId = (int) $pse->getId();
            if (!\array_key_exists($pseId, $prices) && !\array_key_exists($pseId, $quantities)) {
                continue;
            }

            try {
                $written = $this->writer->save(
                    (int) $saleUnit->getId(),
                    (int) $currency->getId(),
                    $pseId,
                    self::stringOrNull($prices[$pseId] ?? null),
                    self::stringOrNull($promoPrices[$pseId] ?? null),
                    self::stringOrNull($quantities[$pseId] ?? null),
                ) || $written;
            } catch (\InvalidArgumentException) {
                $refused[] = (string) $pse->getRef();
            }
        }

        if ($written) {
            $this->adminLogger->log(
                AdminResources::SALE_UNIT,
                AccessManager::UPDATE,
                \sprintf('Prices and stocks of product %d saved for sale unit %s', $productId, $saleUnit->getCode()),
                (int) $saleUnit->getId(),
            );
        }

        if ([] !== $refused) {
            $this->flash($request, 'warning', $this->translator->trans(
                'Not saved for %refs%: amounts must be numbers, and a promo price needs a price.',
                ['%refs%' => implode(', ', $refused)],
            ));
        } else {
            $this->flash($request, 'success', $this->translator->trans('The prices and stocks of the sale unit have been saved.'));
        }

        return $redirect;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof Session) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
