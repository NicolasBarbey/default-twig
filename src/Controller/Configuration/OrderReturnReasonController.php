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

use BackOfficeDefaultTwigBundle\Form\OrderReturn\OrderReturnReasonType;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\OrderReturnReason\OrderReturnReasonCreateEvent;
use Thelia\Core\Event\OrderReturnReason\OrderReturnReasonDeleteEvent;
use Thelia\Core\Event\OrderReturnReason\OrderReturnReasonEvent;
use Thelia\Core\Event\OrderReturnReason\OrderReturnReasonUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderReturnReason;
use Thelia\Model\OrderReturnReasonQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The merchant-managed list of return reasons: a translated reference table, on
 * the model of the order statuses screen.
 */
#[Route('/admin/configuration/order-return-reason', name: 'admin.order-return-reason.')]
final class OrderReturnReasonController
{
    private const RESOURCE = AdminResources::ORDER_RETURN_REASON;
    private const LIST_ROUTE = 'admin.order-return-reason.default';
    private const EDIT_ROUTE = 'admin.order-return-reason.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/order-return-reason/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/order-return-reason/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
        private readonly ReturnEligibilityChecker $eligibility,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request)));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertFeatureEnabled();

        $form = $this->formFactory->createNamed('thelia_order_return_reason_creation', OrderReturnReasonType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::ORDER_RETURN_REASON_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Return reason creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            describeForLog: self::describeForLog('Return reason "%s" created'),
        );
    }

    #[Route('/update/{order_return_reason_id}', name: 'update', methods: ['GET'], requirements: ['order_return_reason_id' => '\d+'])]
    public function updateView(Request $request, int $order_return_reason_id): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $reason = OrderReturnReasonQuery::create()->findPk($order_return_reason_id);

        if ($reason === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $reason->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'reason' => $reason,
            'form' => $this->buildUpdateForm($reason, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save/{order_return_reason_id}', name: 'save', methods: ['POST'], requirements: ['order_return_reason_id' => '\d+'])]
    public function processUpdate(int $order_return_reason_id): Response
    {
        $this->assertFeatureEnabled();

        $form = $this->formFactory->createNamed('thelia_order_return_reason_modification', OrderReturnReasonType::class, null, [
            'include_id' => true,
            'include_description' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::ORDER_RETURN_REASON_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Return reason update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['order_return_reason_id' => $order_return_reason_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['order_return_reason_id' => $order_return_reason_id])),
            describeForLog: self::describeForLog('Return reason "%s" updated'),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $this->assertFeatureEnabled();

        // The confirm dialog of the theme carries the reason and the token in the
        // query string, a posted form carries them in the body: read both.
        $reasonId = (int) ($request->request->get('order_return_reason_id') ?? $request->query->get('order_return_reason_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new OrderReturnReasonDeleteEvent($reasonId),
            eventName: TheliaEvents::ORDER_RETURN_REASON_DELETE,
            actionLabel: 'Return reason deletion',
            successRoute: self::LIST_ROUTE,
            describeForLog: static fn (OrderReturnReasonDeleteEvent $event): array => [
                \sprintf('Return reason #%d deleted', $event->getId()),
                $event->getId(),
            ],
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $this->assertFeatureEnabled();

        $event = new UpdatePositionEvent(
            (int) ($request->query->get('order_return_reason_id') ?? $request->request->get('order_return_reason_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_RETURN_REASON_UPDATE_POSITION,
            actionLabel: 'Return reason reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function createEvent(FormInterface $validated): OrderReturnReasonCreateEvent
    {
        return $this->fillEvent(new OrderReturnReasonCreateEvent(), $validated);
    }

    private function updateEvent(FormInterface $validated): OrderReturnReasonUpdateEvent
    {
        $data = $validated->getData() ?? [];

        return $this->fillEvent(new OrderReturnReasonUpdateEvent((int) ($data['id'] ?? 0)), $validated);
    }

    /**
     * @template TEvent of OrderReturnReasonEvent
     *
     * @param TEvent $event
     *
     * @return TEvent
     */
    private function fillEvent(OrderReturnReasonEvent $event, FormInterface $validated): OrderReturnReasonEvent
    {
        $data = $validated->getData() ?? [];
        $code = (string) ($data['code'] ?? '');

        $event
            ->setLocale((string) ($data['locale'] ?? 'en_US'))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setCode($code === '' ? null : $code)
            ->setVisible((bool) ($data['visible'] ?? false))
            ->setDescription((string) ($data['description'] ?? ''));

        return $event;
    }

    /**
     * @return callable(OrderReturnReasonEvent): array{0: string, 1: int|null}
     */
    private static function describeForLog(string $format): callable
    {
        return static function (OrderReturnReasonEvent $event) use ($format): array {
            $reason = $event->getOrderReturnReason();

            return [
                \sprintf($format, (string) $reason?->getTitle()),
                $reason !== null ? (int) $reason->getId() : null,
            ];
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(Request $request): array
    {
        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'code', 'title', 'position'], 'position');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;

        $query = OrderReturnReasonQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'code' => $query->orderByCode($criteria),
            'title' => $query->useOrderReturnReasonI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderByPosition($criteria),
        };

        // One count query for the whole page instead of one per reason.
        $returnCounts = $this->countReturnsByReason();

        $rows = [];
        foreach ($query->find() as $reason) {
            \assert($reason instanceof OrderReturnReason);
            $reason->setLocale($locale);
            $rows[] = $this->reasonToRow($reason, $returnCounts);
        }

        $createForm = $this->formFactory->createNamed('thelia_order_return_reason_creation', OrderReturnReasonType::class, [
            'locale' => $locale,
            'visible' => true,
        ]);

        return [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'update_position_url' => $this->urls->generate('admin.order-return-reason.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'delete_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function countReturnsByReason(): array
    {
        $counts = [];
        $rows = OrderReturnQuery::create()
            ->filterByReasonId(null, Criteria::ISNOTNULL)
            ->withColumn('COUNT(*)', 'return_count')
            ->groupByReasonId()
            ->select(['ReasonId', 'return_count'])
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['ReasonId']] = (int) $row['return_count'];
        }

        return $counts;
    }

    /**
     * @param array<int, int> $returnCounts
     *
     * @return array<string, mixed>
     */
    private function reasonToRow(OrderReturnReason $reason, array $returnCounts): array
    {
        $id = (int) $reason->getId();

        return [
            'id' => $id,
            'title' => (string) $reason->getTitle(),
            'code' => (string) $reason->getCode(),
            'visible' => (bool) $reason->getVisible(),
            'visible_html' => $this->renderVisibility((bool) $reason->getVisible()),
            'returns_html' => \sprintf(
                '<span class="badge bg-light text-dark border">%d</span>',
                $returnCounts[$id] ?? 0,
            ),
            'position' => (int) $reason->getPosition(),
            '_actions' => [
                new RowAction(
                    kind: 'edit',
                    label: $this->translator->trans('Edit'),
                    href: $this->urls->generate(self::EDIT_ROUTE, ['order_return_reason_id' => $id]),
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: self::RESOURCE,
                ),
                new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete'),
                    modalTarget: '#order-return-reason-delete-modal',
                    grantedAttribute: AccessManager::DELETE,
                    grantedSubject: self::RESOURCE,
                    dataAttributes: [
                        'order-return-reason-id' => $id,
                        'order-return-reason-label' => (string) $reason->getTitle(),
                    ],
                ),
            ],
        ];
    }

    private function renderVisibility(bool $visible): string
    {
        return $visible
            ? '<span class="badge text-bg-success">'.htmlspecialchars($this->translator->trans('Yes'), \ENT_QUOTES | \ENT_HTML5).'</span>'
            : '<span class="badge text-bg-secondary">'.htmlspecialchars($this->translator->trans('No'), \ENT_QUOTES | \ENT_HTML5).'</span>';
    }

    private function buildUpdateForm(OrderReturnReason $reason, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_order_return_reason_modification', OrderReturnReasonType::class, [
            'id' => $reason->getId(),
            'locale' => $locale,
            'title' => $reason->getTitle(),
            'code' => $reason->getCode(),
            'visible' => (bool) $reason->getVisible(),
            'description' => $reason->getDescription(),
        ], [
            'include_id' => true,
            'include_description' => true,
        ]);
    }

    private function assertFeatureEnabled(): void
    {
        if (!$this->eligibility->isFeatureEnabled()) {
            throw new NotFoundHttpException();
        }
    }
}
