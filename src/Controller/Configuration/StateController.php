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

use BackOfficeDefaultTwigBundle\Form\Configuration\StateType;
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
use Thelia\Core\Event\State\StateCreateEvent;
use Thelia\Core\Event\State\StateDeleteEvent;
use Thelia\Core\Event\State\StateToggleVisibilityEvent;
use Thelia\Core\Event\State\StateUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CountryQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\CountryI18nTableMap;
use Thelia\Model\Map\StateI18nTableMap;
use Thelia\Model\State;
use Thelia\Model\StateQuery;
use Twig\Environment;

#[Route('/admin/configuration/states', name: 'admin.configuration.states.')]
final class StateController
{
    private const RESOURCE = AdminResources::STATE;
    private const PAGE_SIZE = 20;
    private const LIST_ROUTE = 'admin.configuration.states.default';
    private const EDIT_ROUTE = 'admin.configuration.states.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/state/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/state/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
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

        $locale = $request->getLocale();
        $countryFilter = (int) $request->query->get('country_id', 0);
        $sort = ListSort::fromRequest($request, ['id', 'title', 'iso_code'], 'title');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = StateQuery::create()->joinWithI18n($locale);
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'iso_code' => $query->orderByIsocode($criteria),
            default => $query->orderBy(StateI18nTableMap::COL_TITLE, $criteria),
        };
        if ($countryFilter > 0) {
            $query->filterByCountryId($countryFilter);
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) $request->query->get('page', 1)), $lastPage);
        $query->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE);

        $rows = [];
        foreach ($query->find() as $state) {
            \assert($state instanceof State);
            $state->setLocale($locale);
            $rows[] = $this->stateToRow($state);
        }

        $createForm = $this->formFactory->createNamed('thelia_state_create', StateType::class, [
            'locale' => $locale,
            'visible' => true,
        ], [
            'country_choices' => $this->countryChoiceMap($locale),
        ]);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'countries' => $this->countryChoices($locale),
            'current_country' => $countryFilter,
            'create_form' => $createForm->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
            'current_page' => $page,
            'last_page' => $lastPage,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_state_create', StateType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ], [
            'country_choices' => $this->countryChoiceMap($request->getLocale()),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::STATE_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'State creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{state_id}', name: 'update', methods: ['GET'], requirements: ['state_id' => '\d+'])]
    public function updateView(int $state_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $state = StateQuery::create()->findPk($state_id);
        if ($state === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $state->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'state' => $state,
            'form' => $this->buildUpdateForm($state, $locale)->createView(),
            'countries' => $this->countryChoices($locale),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_state_update', StateType::class, null, [
            'include_id' => true,
            'country_choices' => $this->countryChoiceMap($request->getLocale()),
        ]);

        $stateId = (int) $request->request->get('state_id', 0);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::STATE_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'State update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['state_id' => $stateId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['state_id' => $stateId])),
        );
    }

    #[Route('/toggle-visibility', name: 'toggle-visibility', methods: ['POST'])]
    public function toggleVisibility(Request $request): Response
    {
        $state = StateQuery::create()->findPk((int) ($request->query->get('state_id') ?? $request->request->get('state_id', 0)));
        if ($state === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new StateToggleVisibilityEvent($state),
            eventName: TheliaEvents::STATE_TOGGLE_VISIBILITY,
            actionLabel: 'State visibility',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new StateDeleteEvent((int) ($request->query->get('state_id') ?? $request->request->get('state_id', 0))),
            eventName: TheliaEvents::STATE_DELETE,
            actionLabel: 'State deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function createEvent(FormInterface $validated): StateCreateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new StateCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setIsocode((string) ($data['isocode'] ?? ''))
            ->setCountry((int) ($data['country'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false));

        return $event;
    }

    private function updateEvent(FormInterface $validated): StateUpdateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new StateUpdateEvent((int) ($data['id'] ?? 0));
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setIsocode((string) ($data['isocode'] ?? ''))
            ->setCountry((int) ($data['country'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false));

        return $event;
    }

    private function buildUpdateForm(State $state, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_state_update', StateType::class, [
            'id' => $state->getId(),
            'locale' => $locale,
            'title' => $state->getTitle(),
            'isocode' => $state->getIsocode(),
            'country' => $state->getCountryId(),
            'visible' => (bool) $state->getVisible(),
        ], [
            'include_id' => true,
            'country_choices' => $this->countryChoiceMap($locale),
        ]);
    }

    /** @return array<string, mixed> */
    private function stateToRow(State $state): array
    {
        $id = (int) $state->getId();
        $actions = [
            new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['state_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: self::RESOURCE),
            new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#state-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['state-id' => $id, 'state-label' => (string) $state->getTitle()]),
        ];

        return [
            'id' => $id,
            'title' => (string) $state->getTitle(),
            'isocode' => (string) $state->getIsocode(),
            'country' => (string) $state->getCountry()->getTitle(),
            'visible' => (bool) $state->getVisible(),
            'toggle_visible_url' => $this->urls->generate('admin.configuration.states.toggle-visibility', ['state_id' => $id]),
            '_actions' => $actions,
        ];
    }

    /** @return list<array{id: int, title: string}> */
    private function countryChoices(string $locale): array
    {
        $countries = CountryQuery::create()
            ->joinWithI18n($locale)
            ->orderBy(CountryI18nTableMap::COL_TITLE)
            ->find();
        $rows = [];
        foreach ($countries as $country) {
            $country->setLocale($locale);
            $title = (string) $country->getTitle();
            if ($title === '') {
                continue;
            }
            $rows[] = ['id' => (int) $country->getId(), 'title' => $title];
        }

        return $rows;
    }

    /** @return array<string, int> */
    private function countryChoiceMap(string $locale): array
    {
        $map = [];
        foreach ($this->countryChoices($locale) as $choice) {
            $map[$choice['title']] = $choice['id'];
        }

        return $map;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
