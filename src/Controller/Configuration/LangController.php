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

use BackOfficeDefaultTwigBundle\Form\Lang\LangDefaultBehaviorType;
use BackOfficeDefaultTwigBundle\Form\Lang\LangType;
use BackOfficeDefaultTwigBundle\Form\Lang\LangUrlType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Lang\LangCreateEvent;
use Thelia\Core\Event\Lang\LangDefaultBehaviorEvent;
use Thelia\Core\Event\Lang\LangDeleteEvent;
use Thelia\Core\Event\Lang\LangToggleActiveEvent;
use Thelia\Core\Event\Lang\LangToggleDefaultEvent;
use Thelia\Core\Event\Lang\LangToggleVisibleEvent;
use Thelia\Core\Event\Lang\LangUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Lang\LangUrlEvent;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/languages', name: 'admin.configuration.languages.')]
final class LangController
{
    private const RESOURCE = AdminResources::LANGUAGE;
    private const LIST_ROUTE = 'admin.configuration.languages.default';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/lang/list.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly TokenProvider $tokens,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
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

    #[Route('/update/{lang_id}', name: 'update', requirements: ['lang_id' => '\d+'], methods: ['GET'])]
    public function updateRedirect(): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/save/{lang_id}', name: 'update.process', requirements: ['lang_id' => '\d+'], methods: ['POST'])]
    public function processUpdate(int $lang_id): Response
    {
        // Each row's edit form is registered as 'thelia_lang_update_<id>' (see createEditForm),
        // so the posted fields are namespaced by id. Rebuild the form under the same name here,
        // otherwise handleRequest() never sees the submission and the update silently fails.
        $form = $this->formFactory->createNamed('thelia_lang_update_'.$lang_id, LangType::class, null, [
            'include_id' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::LANG_UPDATE,
            eventFactory: function (FormInterface $validated): LangUpdateEvent {
                $event = new LangUpdateEvent((int) $validated->get('id')->getData());
                $this->hydrateLangEvent($event, $validated);

                return $event;
            },
            actionLabel: 'Language update',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): Response => $this->renderListWithError(),
            describeForLog: fn (LangUpdateEvent $event): array => $this->describeLangUpdate($event),
        );
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(): Response
    {
        $form = $this->formFactory->createNamed('thelia_language_create', LangType::class, null, [
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::LANG_CREATE,
            eventFactory: function (FormInterface $validated): LangCreateEvent {
                $event = new LangCreateEvent();
                $this->hydrateLangEvent($event, $validated);

                return $event;
            },
            actionLabel: 'Language creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): Response => $this->renderListWithError(),
            describeForLog: fn (LangCreateEvent $event): array => $this->describeLangCreated($event),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new LangDeleteEvent((int) ($request->query->get('language_id') ?? $request->request->get('language_id', 0))),
            eventName: TheliaEvents::LANG_DELETE,
            actionLabel: 'Language deletion',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): Response => $this->renderListWithError(),
        );
    }

    #[Route('/toggleDefault/{lang_id}', name: 'toggleDefault', requirements: ['lang_id' => '\d+'], methods: ['POST'])]
    public function toggleDefault(int $lang_id, Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new LangToggleDefaultEvent($lang_id),
            eventName: TheliaEvents::LANG_TOGGLEDEFAULT,
            actionLabel: 'Language toggle',
            successRoute: self::LIST_ROUTE,
            describeForLog: fn (LangToggleDefaultEvent $event): array => $this->describeLangUpdate($event),
        );
    }

    #[Route('/toggleActive/{lang_id}', name: 'toggleActive', requirements: ['lang_id' => '\d+'], methods: ['POST'])]
    public function toggleActive(int $lang_id, Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new LangToggleActiveEvent($lang_id),
            eventName: TheliaEvents::LANG_TOGGLEACTIVE,
            actionLabel: 'Language toggle',
            successRoute: self::LIST_ROUTE,
            describeForLog: fn (LangToggleActiveEvent $event): array => $this->describeLangUpdate($event),
        );
    }

    #[Route('/toggleVisible/{lang_id}', name: 'toggleVisible', requirements: ['lang_id' => '\d+'], methods: ['POST'])]
    public function toggleVisible(int $lang_id, Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new LangToggleVisibleEvent($lang_id),
            eventName: TheliaEvents::LANG_TOGGLEVISIBLE,
            actionLabel: 'Language toggle',
            successRoute: self::LIST_ROUTE,
            describeForLog: fn (LangToggleVisibleEvent $event): array => $this->describeLangUpdate($event),
        );
    }

    #[Route('/defaultBehavior', name: 'defaultBehavior', methods: ['POST'])]
    public function defaultBehavior(): Response
    {
        $form = $this->formFactory->createNamed('thelia_lang_defaultBehavior', LangDefaultBehaviorType::class, null, [
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::LANG_DEFAULTBEHAVIOR,
            eventFactory: static fn (FormInterface $validated): LangDefaultBehaviorEvent => new LangDefaultBehaviorEvent(
                (int) $validated->get('behavior')->getData(),
            ),
            actionLabel: 'Default behavior update',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): Response => $this->renderListWithError(),
        );
    }

    #[Route('/updateUrl', name: 'updateUrl', methods: ['POST'])]
    public function updateUrl(): Response
    {
        $form = $this->formFactory->createNamed('thelia_language_url', LangUrlType::class, null, [
            'languages' => $this->buildLanguageUrlOptions(),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::LANG_URL,
            eventFactory: static function (FormInterface $validated): LangUrlEvent {
                $event = new LangUrlEvent();
                foreach ($validated->getData() as $key => $value) {
                    if (str_starts_with((string) $key, LangUrlType::FIELD_PREFIX)) {
                        $event->addUrl(substr((string) $key, \strlen(LangUrlType::FIELD_PREFIX)), $value);
                    }
                }

                return $event;
            },
            actionLabel: 'Language URL update',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): Response => $this->renderListWithError(),
        );
    }

    #[Route('/domain/activate', name: 'domain.activation', methods: ['POST'])]
    public function activateDomain(Request $request): Response
    {
        return $this->switchDomainPerLang(true, $request);
    }

    #[Route('/domain/deactivate', name: 'domain.deactivation', methods: ['POST'])]
    public function deactivateDomain(Request $request): Response
    {
        return $this->switchDomainPerLang(false, $request);
    }

    private function switchDomainPerLang(bool $activate, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        if ($activate && ($missing = $this->frontLanguagesWithoutUrl()) !== []) {
            $this->flashError($request, $this->translator->trans(
                'Define a domain for every language served in front office before activating this setting. Missing: %languages.',
                ['%languages' => implode(', ', $missing)],
            ));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        ConfigQuery::create()
            ->filterByName('one_domain_foreach_lang')
            ->update(['Value' => $activate ? 1 : 0], null, true);

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    private function hydrateLangEvent(LangCreateEvent $event, FormInterface $form): void
    {
        $event
            ->setTitle($form->get('title')->getData())
            ->setCode($form->get('code')->getData())
            ->setLocale($form->get('locale')->getData())
            ->setDateTimeFormat($form->get('date_time_format')->getData())
            ->setDateFormat($form->get('date_format')->getData())
            ->setTimeFormat($form->get('time_format')->getData())
            ->setDecimalSeparator($form->get('decimal_separator')->getData())
            ->setThousandsSeparator($form->get('thousands_separator')->getData() ?? '')
            ->setDecimals((string) $form->get('decimals')->getData());
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeLangCreated(LangCreateEvent $event): array
    {
        if (!$event->hasLang()) {
            throw new \LogicException($this->translator->trans('No language was created.'));
        }

        $lang = $event->getLang();

        return [\sprintf('Lang %s (ID %d) created', $lang->getTitle(), $lang->getId()), $lang->getId()];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeLangUpdate(\Thelia\Core\Event\Lang\LangEvent $event): array
    {
        if (!$event->hasLang()) {
            throw new \LogicException($this->translator->trans('No language was updated.'));
        }

        $lang = $event->getLang();

        return [\sprintf('Lang %s (ID %d) modified', $lang->getTitle(), $lang->getId()), $lang->getId()];
    }

    private function flashError(Request $request, string $message): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', $message);
        }
    }

    private function renderListWithError(): Response
    {
        return new Response(
            $this->twig->render(self::LIST_TEMPLATE, $this->buildListContext()),
            Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(?Request $request = null): array
    {
        $sort = $request !== null
            ? ListSort::fromRequest($request, ['id', 'title', 'code', 'locale', 'position'], 'position')
            : new ListSort('position', 'asc');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = LangQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'title' => $query->orderByTitle($criteria),
            'code' => $query->orderByCode($criteria),
            'locale' => $query->orderByLocale($criteria),
            default => $query->orderByPosition($criteria),
        };
        $languages = $query->find();
        $rows = [];
        $editForms = [];

        foreach ($languages as $lang) {
            $rows[] = $this->langToRow($lang);
            $editForms[$lang->getId()] = $this->createEditForm($lang)->createView();
        }

        $createForm = $this->formFactory->createNamed('thelia_language_create', LangType::class, null, [
        ]);

        $defaultBehaviorForm = $this->formFactory->createNamed('thelia_lang_defaultBehavior', LangDefaultBehaviorType::class, [
            'behavior' => ConfigQuery::getDefaultLangWhenNoTranslationAvailable(),
        ], [
        ]);

        $urlForm = $this->formFactory->createNamed('thelia_language_url', LangUrlType::class, null, [
            'languages' => $this->buildLanguageUrlOptions(),
        ]);

        return [
            'rows' => $rows,
            'edit_forms' => $editForms,
            'create_form' => $createForm->createView(),
            'default_behavior_form' => $defaultBehaviorForm->createView(),
            'url_form' => $urlForm->createView(),
            'lang_url_prefix' => LangUrlType::FIELD_PREFIX,
            'one_domain_per_lang' => (bool) ConfigQuery::isMultiDomainActivated(),
            'languages_without_url' => $this->frontLanguagesWithoutUrl(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function langToRow(Lang $lang): array
    {
        $id = $lang->getId();
        $isDefault = (bool) $lang->getByDefault();

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit this language'),
                modalTarget: '#lang-edit-modal-'.$id,
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['lang-id' => $id],
            ),
        ];

        if (!$isDefault) {
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete this language'),
                modalTarget: '#lang-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['lang-id' => $id, 'lang-title' => $lang->getTitle()],
            );
        }

        return [
            'id' => $id,
            'title' => $lang->getTitle(),
            'code' => $lang->getCode(),
            'locale' => $lang->getLocale(),
            'visible' => (bool) $lang->getVisible(),
            'active' => (bool) $lang->getActive(),
            'default' => $isDefault,
            'toggle_visible_url' => $this->urls->generate('admin.configuration.languages.toggleVisible', ['lang_id' => $id]),
            'toggle_active_url' => $this->urls->generate('admin.configuration.languages.toggleActive', ['lang_id' => $id]),
            'toggle_default_url' => $this->urls->generate('admin.configuration.languages.toggleDefault', ['lang_id' => $id]),
            '_actions' => $actions,
        ];
    }

    private function createEditForm(Lang $lang): FormInterface
    {
        return $this->formFactory->createNamed('thelia_lang_update_'.$lang->getId(), LangType::class, [
            'id' => $lang->getId(),
            'title' => $lang->getTitle(),
            'code' => $lang->getCode(),
            'locale' => $lang->getLocale(),
            'date_time_format' => $lang->getDateTimeFormat(),
            'date_format' => $lang->getDateFormat(),
            'time_format' => $lang->getTimeFormat(),
            'decimal_separator' => $lang->getDecimalSeparator(),
            'thousands_separator' => $lang->getThousandsSeparator(),
            'decimals' => $lang->getDecimals(),
        ], [
            'include_id' => true,
        ]);
    }

    /**
     * Titles of the languages served in front office (active and visible) that have no domain.
     * Those are the ones LangService looks up once multi-domain is on, so an empty domain there
     * means the language keeps being served from whichever domain the visitor arrived on.
     *
     * @return list<string>
     */
    private function frontLanguagesWithoutUrl(): array
    {
        $titles = [];

        $languages = LangQuery::create()
            ->filterByActive(true)
            ->filterByVisible(1)
            ->orderByPosition()
            ->find();

        foreach ($languages as $lang) {
            if (trim((string) $lang->getUrl()) === '') {
                $titles[] = (string) $lang->getTitle();
            }
        }

        return $titles;
    }

    /**
     * @return list<array{id: int, title: string, url: string}>
     */
    private function buildLanguageUrlOptions(): array
    {
        $options = [];

        foreach (LangQuery::create()->orderByPosition()->find() as $lang) {
            $options[] = [
                'id' => $lang->getId(),
                'title' => $lang->getTitle(),
                'url' => $lang->getUrl() ?? '',
            ];
        }

        return $options;
    }
}
