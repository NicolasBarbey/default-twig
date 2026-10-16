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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Configuration\SaleUnitType;
use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\SaleUnitListPresenter;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\SaleUnitRefusedException;
use BackOfficeDefaultTwigBundle\Service\SaleUnit\SaleUnitWriter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\SaleUnit;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The sale units the shop sells from: one per storefront domain or store, each with its
 * own prices, stocks and orders over the shared catalog. While none is declared, the shop
 * is a single shop and nothing else in the back office mentions them.
 */
#[Route('/admin/configuration/sale-units', name: 'admin.sale-unit.')]
final class SaleUnitController
{
    private const RESOURCE = AdminResources::SALE_UNIT;
    private const LIST_ROUTE = 'admin.sale-unit.default';
    private const EDIT_ROUTE = 'admin.sale-unit.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/sale-unit/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/sale-unit/edit.html.twig';
    private const CREATION_FORM = 'thelia_sale_unit_creation';
    private const MODIFICATION_FORM = 'thelia_sale_unit_modification';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly SaleUnitRepository $saleUnits,
        private readonly SaleUnitWriter $writer,
        private readonly SaleUnitListPresenter $presenter,
        private readonly EditLocaleResolver $editLocale,
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, SaleUnitListPresenter::SORTABLE_FIELDS, 'position');

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->presenter->rows($request->getLocale(), $sort->field, $sort->direction),
            'create_form' => $this->creationForm($request->getLocale())->createView(),
            'update_position_url' => $this->urls->generate('admin.sale-unit.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        $form = $this->creationForm($request->getLocale());

        try {
            $data = $this->validator->validate($form)->getData();
            $saleUnit = $this->writer->create(
                (string) $data['code'],
                $data['url'] ?? null,
                (string) $data['locale'],
                (string) $data['title'],
                $data['description'] ?? null,
                (bool) ($data['active'] ?? false),
            );
        } catch (FormValidationException|SaleUnitRefusedException $refusal) {
            $this->errorRenderer->setup($this->translator->trans('Sale unit creation'), $refusal->getMessage(), null, $refusal);

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::CREATE, \sprintf('Sale unit %s created', $saleUnit->getCode()), (int) $saleUnit->getId());

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['sale_unit_id' => $saleUnit->getId()]));
    }

    #[Route('/update/{sale_unit_id}', name: 'update', methods: ['GET'], requirements: ['sale_unit_id' => '\d+'])]
    public function updateView(int $sale_unit_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $saleUnit = $this->saleUnits->find($sale_unit_id);
        if (null === $saleUnit) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'sale_unit' => $saleUnit->setLocale($locale),
            'sale_unit_orders' => $this->saleUnits->countOrders($sale_unit_id),
            'form' => $this->modificationForm($saleUnit, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save/{sale_unit_id}', name: 'save', methods: ['POST'], requirements: ['sale_unit_id' => '\d+'])]
    public function processUpdate(int $sale_unit_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $saleUnit = $this->saleUnits->find($sale_unit_id);
        if (null === $saleUnit) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $redirect = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['sale_unit_id' => $sale_unit_id]));
        $form = $this->formFactory->createNamed(self::MODIFICATION_FORM, SaleUnitType::class, null, ['include_id' => true]);

        try {
            $data = $this->validator->validate($form)->getData();
            $this->writer->update(
                $saleUnit,
                (string) $data['code'],
                $data['url'] ?? null,
                (string) $data['locale'],
                (string) $data['title'],
                $data['description'] ?? null,
                (bool) ($data['active'] ?? false),
            );
        } catch (FormValidationException|SaleUnitRefusedException $refusal) {
            $this->errorRenderer->setup($this->translator->trans('Sale unit update'), $refusal->getMessage(), null, $refusal);

            return $redirect;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Sale unit %s updated', $saleUnit->getCode()), $sale_unit_id);

        return $redirect;
    }

    #[Route('/toggle-active', name: 'toggle-active', methods: ['POST'])]
    public function toggleActive(Request $request): Response
    {
        return $this->tokenWrite($request, AccessManager::UPDATE, 'Sale unit activation toggle', function (SaleUnit $saleUnit): string {
            $this->writer->toggleActive($saleUnit);

            return \sprintf('Sale unit %s turned %s', $saleUnit->getCode(), $saleUnit->getActive() ? 'on' : 'off');
        });
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $position = (int) ($request->query->get('position') ?? $request->request->get('position', 0));

        return $this->tokenWrite($request, AccessManager::UPDATE, 'Sale unit reorder', function (SaleUnit $saleUnit) use ($position): string {
            $this->writer->moveTo($saleUnit, $position);

            return \sprintf('Sale unit %s moved to position %d', $saleUnit->getCode(), $position);
        });
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->tokenWrite($request, AccessManager::DELETE, 'Sale unit deletion', function (SaleUnit $saleUnit): string {
            $code = (string) $saleUnit->getCode();
            $this->writer->delete($saleUnit);

            return \sprintf('Sale unit %s deleted', $code);
        });
    }

    /**
     * The road of the token-protected writes on one unit of the list: right, token read
     * from the body, write, admin log, back to the list with the refusal as a message.
     *
     * @param callable(SaleUnit): string $write returns the line of the admin log
     */
    private function tokenWrite(Request $request, string $access, string $actionLabel, callable $write): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], $access)) {
            return $denied;
        }

        $redirect = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        $saleUnitId = (int) ($request->query->get('sale_unit_id') ?? $request->request->get('sale_unit_id', 0));
        $saleUnit = $this->saleUnits->find($saleUnitId);
        if (null === $saleUnit) {
            return $redirect;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
            $logLine = $write($saleUnit);
        } catch (SaleUnitRefusedException $refusal) {
            $this->errorRenderer->setup($this->translator->trans($actionLabel), $refusal->getMessage(), null, $refusal);

            return $redirect;
        } catch (\Throwable $failure) {
            $this->errorRenderer->setup($this->translator->trans($actionLabel), $this->translator->trans('The sale unit could not be saved. Please try again.'), null, $failure);

            return $redirect;
        }

        $this->adminLogger->log(self::RESOURCE, $access, $logLine, $saleUnitId);

        return $redirect;
    }

    private function creationForm(string $locale): FormInterface
    {
        return $this->formFactory->createNamed(self::CREATION_FORM, SaleUnitType::class, [
            'locale' => $locale,
            'active' => true,
        ]);
    }

    private function modificationForm(SaleUnit $saleUnit, string $locale): FormInterface
    {
        return $this->formFactory->createNamed(self::MODIFICATION_FORM, SaleUnitType::class, [
            'id' => $saleUnit->getId(),
            'locale' => $locale,
            'code' => $saleUnit->getCode(),
            'url' => $saleUnit->getUrl(),
            'title' => $saleUnit->getTitle(),
            'description' => $saleUnit->getDescription(),
            'active' => (bool) $saleUnit->getActive(),
        ], ['include_id' => true]);
    }
}
