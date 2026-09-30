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

use BackOfficeDefaultTwigBundle\Form\Configuration\CountryType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Country\CountryCreateEvent;
use Thelia\Core\Event\Country\CountryDeleteEvent;
use Thelia\Core\Event\Country\CountryToggleDefaultEvent;
use Thelia\Core\Event\Country\CountryToggleVisibilityEvent;
use Thelia\Core\Event\Country\CountryUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\AreaQuery;
use Thelia\Model\Country;
use Thelia\Model\CountryAreaQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\CountryI18nTableMap;
use Thelia\Model\StateQuery;
use Twig\Environment;

#[Route('/admin/configuration/countries', name: 'admin.configuration.countries.')]
final class CountryController
{
    private const RESOURCE = AdminResources::COUNTRY;
    private const LIST_ROUTE = 'admin.configuration.countries.default';
    private const EDIT_ROUTE = 'admin.configuration.countries.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/country/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/country/edit.html.twig';

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
        $sort = ListSort::fromRequest($request, ['id', 'title', 'iso_code', 'iso_alpha2', 'iso_alpha3'], 'title');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = CountryQuery::create()->joinWithI18n($locale);
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'iso_code' => $query->orderByIsocode($criteria),
            'iso_alpha2' => $query->orderByIsoalpha2($criteria),
            'iso_alpha3' => $query->orderByIsoalpha3($criteria),
            default => $query->orderBy(CountryI18nTableMap::COL_TITLE, $criteria),
        };
        $countries = $query->find();
        $zonesByCountry = $this->shippingZonesByCountry();
        $rows = [];
        $countriesWithoutZone = 0;
        foreach ($countries as $country) {
            \assert($country instanceof Country);
            $country->setLocale($locale);
            $zones = $zonesByCountry[(int) $country->getId()] ?? [];
            if ($zones === [] && $country->getVisible()) {
                ++$countriesWithoutZone;
            }
            $rows[] = $this->countryToRow($country, $zones);
        }

        $createForm = $this->formFactory->createNamed('thelia_country_create', CountryType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ], []);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'countries_without_zone' => $countriesWithoutZone,
            'create_form' => $createForm->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_country_create', CountryType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ], []);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::COUNTRY_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Country creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{country_id}', name: 'update', methods: ['GET'], requirements: ['country_id' => '\d+'])]
    public function updateView(int $country_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $country = CountryQuery::create()->findPk($country_id);
        if ($country === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $country->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'country' => $country,
            'form' => $this->buildUpdateForm($country, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_country_update', CountryType::class, null, [
            'include_id' => true,
        ]);

        $countryId = (int) $request->request->get('country_id', 0);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::COUNTRY_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Country update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['country_id' => $countryId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['country_id' => $countryId])),
        );
    }

    #[Route('/toggle-visibility', name: 'toggle-visibility', methods: ['POST'])]
    public function toggleVisibility(Request $request): Response
    {
        $country = CountryQuery::create()->findPk((int) ($request->query->get('country_id') ?? $request->request->get('country_id', 0)));
        if ($country === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CountryToggleVisibilityEvent($country),
            eventName: TheliaEvents::COUNTRY_TOGGLE_VISIBILITY,
            actionLabel: 'Country visibility',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/toggle-default', name: 'toggle-default', methods: ['POST'])]
    public function toggleDefault(Request $request): Response
    {
        $country = CountryQuery::create()->findPk((int) ($request->query->get('country_id') ?? $request->request->get('country_id', 0)));
        if ($country === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CountryToggleDefaultEvent($country),
            eventName: TheliaEvents::COUNTRY_TOGGLE_DEFAULT,
            actionLabel: 'Country default',
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
            event: new CountryDeleteEvent((int) ($request->query->get('country_id') ?? $request->request->get('country_id', 0))),
            eventName: TheliaEvents::COUNTRY_DELETE,
            actionLabel: 'Country deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/data', name: 'data', methods: ['GET'])]
    public function data(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $visible = $request->query->getBoolean('visible', true);
        $locale = (string) ($request->query->get('locale') ?? $this->defaultLocale());

        $countriesQuery = CountryQuery::create();
        if ($visible) {
            $countriesQuery->filterByVisible(true);
        }
        $countriesQuery->joinWithI18n($locale);

        // A shipping zone can hold a state as soon as the country carries one:
        // country.has_states only says whether an address form may leave the
        // choice empty, and France carries its 101 departments with the flag off.
        $statesQuery = StateQuery::create();
        if ($visible) {
            $statesQuery->filterByVisible(true);
        }
        $statesQuery->joinWithI18n($locale);

        $statesByCountry = [];
        foreach ($statesQuery->find() as $state) {
            $statesByCountry[(int) $state->getCountryId()][] = [
                'id' => (int) $state->getId(),
                'title' => (string) $state->getTitle(),
            ];
        }

        $payload = [];
        foreach ($countriesQuery->find() as $country) {
            \assert($country instanceof Country);
            $states = $statesByCountry[(int) $country->getId()] ?? [];

            $payload[] = [
                'id' => (int) $country->getId(),
                'title' => (string) $country->getTitle(),
                'hasStates' => [] !== $states,
                'states' => $states,
            ];
        }

        return new JsonResponse($payload);
    }

    private function createEvent(FormInterface $validated): CountryCreateEvent
    {
        $data = $validated->getData() ?? [];
        // CountryCreateEvent only supports the base fields; chapo/description/
        // postscriptum and the zip-code settings are exposed on CountryUpdateEvent
        // only, so they are edited from the country edit screen after creation.
        $event = new CountryCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setIsocode((string) ($data['isocode'] ?? ''))
            ->setIsoAlpha2((string) ($data['isoalpha2'] ?? ''))
            ->setIsoAlpha3((string) ($data['isoalpha3'] ?? ''))
            ->setArea((int) ($data['area'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false))
            ->setHasStates((bool) ($data['has_states'] ?? false));

        return $event;
    }

    private function updateEvent(FormInterface $validated): CountryUpdateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new CountryUpdateEvent((int) ($data['id'] ?? 0));
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setIsocode((string) ($data['isocode'] ?? ''))
            ->setIsoAlpha2((string) ($data['isoalpha2'] ?? ''))
            ->setIsoAlpha3((string) ($data['isoalpha3'] ?? ''))
            ->setArea((int) ($data['area'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false))
            ->setHasStates((bool) ($data['has_states'] ?? false))
            ->setNeedZipCode((bool) ($data['need_zip_code'] ?? false))
            ->setZipCodeFormat((string) ($data['zip_code_format'] ?? ''))
            ->setChapo((string) ($data['chapo'] ?? ''))
            ->setDescription((string) ($data['description'] ?? ''))
            ->setPostscriptum((string) ($data['postscriptum'] ?? ''));

        return $event;
    }

    private function buildUpdateForm(Country $country, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_country_update', CountryType::class, [
            'id' => $country->getId(),
            'locale' => $locale,
            'title' => $country->getTitle(),
            'isocode' => $country->getIsocode(),
            'isoalpha2' => $country->getIsoalpha2(),
            'isoalpha3' => $country->getIsoalpha3(),
            'area' => null,
            'visible' => (bool) $country->getVisible(),
            'has_states' => (bool) $country->getHasStates(),
            'need_zip_code' => (bool) $country->getNeedZipCode(),
            'zip_code_format' => (string) $country->getZipCodeFormat(),
            'chapo' => $country->getChapo(),
            'description' => $country->getDescription(),
            'postscriptum' => $country->getPostscriptum(),
        ], [
            'include_id' => true,
        ]);
    }

    /** @return array<string, mixed> */
    /**
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function shippingZonesByCountry(): array
    {
        $areaNames = [];
        foreach (AreaQuery::create()->find() as $area) {
            $areaNames[(int) $area->getId()] = (string) $area->getName();
        }

        $map = [];
        foreach (CountryAreaQuery::create()->find() as $countryArea) {
            $areaId = (int) $countryArea->getAreaId();
            $map[(int) $countryArea->getCountryId()][] = ['id' => $areaId, 'name' => $areaNames[$areaId] ?? ''];
        }

        return $map;
    }

    /**
     * @param list<array{id: int, name: string}> $zones
     */
    private function renderShippingZones(array $zones): string
    {
        if ($zones === []) {
            return '<span class="text-muted small">'.htmlspecialchars($this->translator->trans('None'), \ENT_QUOTES | \ENT_HTML5).'</span>';
        }

        $badges = '';
        foreach ($zones as $zone) {
            $badges .= '<span class="badge bg-light text-dark border me-1">'.htmlspecialchars($zone['name'], \ENT_QUOTES | \ENT_HTML5).'</span>';
        }

        return $badges;
    }

    /**
     * @param list<array{id: int, name: string}> $zones
     */
    private function countryToRow(Country $country, array $zones = []): array
    {
        $id = (int) $country->getId();
        $actions = [
            new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['country_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: self::RESOURCE),
            new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#country-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['country-id' => $id, 'country-label' => (string) $country->getTitle()]),
        ];

        return [
            'id' => $id,
            'title' => (string) $country->getTitle(),
            'isoalpha2' => (string) $country->getIsoalpha2(),
            'isoalpha3' => (string) $country->getIsoalpha3(),
            'isocode' => (string) $country->getIsocode(),
            'visible' => (bool) $country->getVisible(),
            'default' => (bool) $country->getByDefault(),
            'shipping_zones_html' => $this->renderShippingZones($zones),
            'toggle_visible_url' => $this->urls->generate('admin.configuration.countries.toggle-visibility', ['country_id' => $id]),
            'toggle_default_url' => $this->urls->generate('admin.configuration.countries.toggle-default', ['country_id' => $id]),
            '_actions' => $actions,
        ];
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
