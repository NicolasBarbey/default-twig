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

use BackOfficeDefaultTwigBundle\Form\Configuration\ProductAssociationTypeType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\ProductAssociationType\ProductAssociationTypeCreateEvent;
use Thelia\Core\Event\ProductAssociationType\ProductAssociationTypeDeleteEvent;
use Thelia\Core\Event\ProductAssociationType\ProductAssociationTypeToggleVisibleEvent;
use Thelia\Core\Event\ProductAssociationType\ProductAssociationTypeUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\AccessoryQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductAssociationType;
use Thelia\Model\ProductAssociationTypeQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/product-association-type', name: 'admin.product-association-type.')]
final class ProductAssociationTypeController
{
    private const RESOURCE = AdminResources::CONFIG;
    private const LIST_ROUTE = 'admin.product-association-type.default';
    private const EDIT_ROUTE = 'admin.product-association-type.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/product-association-type/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/product-association-type/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request)));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_product_association_type_creation', ProductAssociationTypeType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::PRODUCT_ASSOCIATION_TYPE_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Product relation type creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{product_association_type_id}', name: 'update', methods: ['GET'], requirements: ['product_association_type_id' => '\d+'])]
    public function updateView(int $product_association_type_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $type = ProductAssociationTypeQuery::create()->findPk($product_association_type_id);
        if ($type === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $type->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'product_association_type' => $type,
            'form' => $this->buildUpdateForm($type, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save/{product_association_type_id}', name: 'save', methods: ['POST'], requirements: ['product_association_type_id' => '\d+'])]
    public function processUpdate(int $product_association_type_id, Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_product_association_type_modification', ProductAssociationTypeType::class, null, [
            'include_id' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::PRODUCT_ASSOCIATION_TYPE_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Product relation type update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_association_type_id' => $product_association_type_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['product_association_type_id' => $product_association_type_id])),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST', 'GET'])]
    public function delete(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new ProductAssociationTypeDeleteEvent($this->requestedTypeId($request)),
            eventName: TheliaEvents::PRODUCT_ASSOCIATION_TYPE_DELETE,
            actionLabel: 'Product relation type deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/toggle-visible', name: 'toggle-visible', methods: ['GET', 'POST'])]
    public function toggleVisible(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ProductAssociationTypeToggleVisibleEvent($this->requestedTypeId($request)),
            eventName: TheliaEvents::PRODUCT_ASSOCIATION_TYPE_TOGGLE_VISIBLE,
            actionLabel: 'Product relation type visibility toggle',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['GET', 'POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            $this->requestedTypeId($request),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_ASSOCIATION_TYPE_UPDATE_POSITION,
            actionLabel: 'Product relation type reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function requestedTypeId(Request $request): int
    {
        return (int) ($request->query->get('product_association_type_id') ?? $request->request->get('product_association_type_id', 0));
    }

    private function createEvent(FormInterface $validated): ProductAssociationTypeCreateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new ProductAssociationTypeCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setCode((string) ($data['code'] ?? ''))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDescription($this->nullableString($data['description'] ?? null))
            ->setVisible((int) (bool) ($data['visible'] ?? true))
            ->setReciprocal((int) (bool) ($data['reciprocal'] ?? false));

        return $event;
    }

    private function updateEvent(FormInterface $validated): ProductAssociationTypeUpdateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new ProductAssociationTypeUpdateEvent((int) ($data['id'] ?? 0));
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDescription($this->nullableString($data['description'] ?? null))
            ->setVisible((int) (bool) ($data['visible'] ?? false))
            ->setReciprocal((int) (bool) ($data['reciprocal'] ?? false));

        return $event;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(Request $request): array
    {
        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'code', 'title', 'position'], 'position');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = ProductAssociationTypeQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'code' => $query->orderByCode($criteria),
            'title' => $query->useProductAssociationTypeI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderByPosition($criteria),
        };

        $relationCounts = $this->relationCounts();
        $rows = [];
        foreach ($query->find() as $type) {
            \assert($type instanceof ProductAssociationType);
            $type->setLocale($locale);
            $rows[] = $this->typeToRow($type, $relationCounts[(int) $type->getId()] ?? 0);
        }

        $createForm = $this->formFactory->createNamed('thelia_product_association_type_creation', ProductAssociationTypeType::class, [
            'locale' => $locale,
            'visible' => true,
        ]);

        return [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'update_position_url' => $this->urls->generate('admin.product-association-type.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ];
    }

    /**
     * How many relations each type holds, in one query: the list offers deletion only
     * where it would go through, rather than on an exception page.
     *
     * @return array<int, int>
     */
    private function relationCounts(): array
    {
        $counts = [];

        $rows = AccessoryQuery::create()
            ->select(['type_id'])
            ->withColumn('COUNT(Accessory.Id)', 'relation_count')
            ->groupBy('TypeId')
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['type_id']] = (int) $row['relation_count'];
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function typeToRow(ProductAssociationType $type, int $relationCount): array
    {
        $id = (int) $type->getId();

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit'),
                href: $this->urls->generate(self::EDIT_ROUTE, ['product_association_type_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
            ),
        ];

        // A type the shop cannot do without, or one still holding relations, is offered no
        // deletion at all: the listener would refuse it, and the merchant is better told
        // by the absence of the button than by an error page.
        if ($type->isDeletable() && 0 === $relationCount) {
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete'),
                modalTarget: '#product-association-type-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['type-id' => $id, 'type-label' => (string) $type->getTitle()],
            );
        }

        return [
            'id' => $id,
            'code' => (string) $type->getCode(),
            'title' => (string) $type->getTitle(),
            'reciprocal' => $type->isReciprocal(),
            'relation_count' => $relationCount,
            'visible' => $type->isVisible(),
            'toggle_visible_url' => $this->tokenizedUrl('admin.product-association-type.toggle-visible', ['product_association_type_id' => $id]),
            'position' => (int) $type->getPosition(),
            '_actions' => $actions,
        ];
    }

    private function buildUpdateForm(ProductAssociationType $type, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_product_association_type_modification', ProductAssociationTypeType::class, [
            'id' => $type->getId(),
            'locale' => $locale,
            'code' => $type->getCode(),
            'title' => $type->getTitle(),
            'description' => $type->getDescription(),
            'visible' => $type->isVisible(),
            'reciprocal' => $type->isReciprocal(),
        ], [
            'include_id' => true,
        ]);
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function tokenizedUrl(string $route, array $parameters): string
    {
        $url = $this->urls->generate($route, $parameters);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'_token='.$this->tokens->assignToken();
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
