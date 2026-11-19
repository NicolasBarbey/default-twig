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

use BackOfficeDefaultTwigBundle\Form\Configuration\SearchTermRedirectType;
use BackOfficeDefaultTwigBundle\Repository\SearchTermRedirectRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\SearchDictionary\RefusedCreationDraft;
use BackOfficeDefaultTwigBundle\Service\SearchDictionary\SearchDictionaryImportAction;
use BackOfficeDefaultTwigBundle\Service\SearchDictionary\SearchDictionaryLanguage;
use BackOfficeDefaultTwigBundle\Service\SearchDictionary\SearchTermRedirectListPresenter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Search\Dictionary\SearchRedirectTargetType;
use Thelia\Domain\Search\Event\SearchTermRedirectWriteEvent;
use Thelia\Model\SearchTermRedirect;
use Twig\Environment;

#[Route('/admin/configuration/search-redirects', name: 'admin.configuration.search_redirects.')]
final class SearchTermRedirectController
{
    private const RESOURCE = AdminResources::CONFIG;
    private const LIST_ROUTE = 'admin.configuration.search_redirects.default';
    private const EDIT_ROUTE = 'admin.configuration.search_redirects.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/search_redirect/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/search_redirect/edit.html.twig';
    private const CREATE_FORM = 'thelia_search_redirect_create';
    private const UPDATE_FORM = 'thelia_search_redirect_update';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly AdminFlash $flash,
        private readonly SearchTermRedirectRepository $redirects,
        private readonly SearchTermRedirectListPresenter $presenter,
        private readonly SearchDictionaryLanguage $languages,
        private readonly SearchDictionaryImportAction $importAction,
        private readonly RefusedCreationDraft $drafts,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $language = $this->languages->resolve($request->query->get('language_id'));
        $sort = ListSort::fromRequest($request, SearchTermRedirectListPresenter::SORTABLE_FIELDS, 'term');
        $page = $this->redirects->page((int) $language->getId(), $sort->direction, (int) $request->query->get('page', 1));

        $createForm = $this->formFactory->createNamed(self::CREATE_FORM, SearchTermRedirectType::class, [
            'lang_id' => (int) $language->getId(),
            'target_type' => SearchRedirectTargetType::PRODUCT,
            'active' => true,
        ]);

        $draft = $this->drafts->take($request, self::CREATE_FORM);
        if (null !== $draft) {
            $createForm = $this->formFactory->createNamed(self::CREATE_FORM, SearchTermRedirectType::class, $draft['data']);
            RefusedCreationDraft::showErrors($createForm, $draft['errors']);
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->presenter->rows($page['items'], (string) $language->getLocale(), $this->access->can(self::RESOURCE, AccessManager::UPDATE)),
            'create_form' => $createForm->createView(),
            'reopen_create' => null !== $draft,
            'language_id' => (int) $language->getId(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
            'current_page' => $page['page'],
            'last_page' => $page['lastPage'],
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed(self::CREATE_FORM, SearchTermRedirectType::class);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::SEARCH_TERM_REDIRECT_CREATE,
            eventFactory: fn (FormInterface $validated): SearchTermRedirectWriteEvent => $this->writeEvent($validated, null),
            actionLabel: 'Search redirection creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => $this->backToListWithDraft($request, $form),
            describeForLog: static fn (SearchTermRedirectWriteEvent $event): array => [\sprintf('Search redirection %s created', $event->getTerm()), $event->getRedirect()?->getId()],
            successParametersResolver: static fn (SearchTermRedirectWriteEvent $event): array => ['language_id' => (int) $event->getRedirect()?->getLangId()],
            afterDispatch: fn (SearchTermRedirectWriteEvent $event) => $this->warnAboutCommonTerm($request, $event),
        );
    }

    #[Route('/update/{search_redirect_id}', name: 'update', methods: ['GET'], requirements: ['search_redirect_id' => '\d+'])]
    public function updateView(int $search_redirect_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $redirect = $this->redirects->find($search_redirect_id);
        if (null === $redirect) {
            return $this->backToList((int) $this->languages->resolve(null)->getId());
        }

        $form = $this->formFactory->createNamed(self::UPDATE_FORM, SearchTermRedirectType::class, [
            'id' => $redirect->getId(),
            'lang_id' => $redirect->getLangId(),
            'term' => $redirect->getTerm(),
            'target_type' => $redirect->getTargetType(),
            'target_id' => $redirect->getTargetId(),
            'url' => $redirect->getUrl(),
            'active' => 1 === $redirect->getActive(),
        ], ['include_id' => true]);

        $response = new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'redirect' => $redirect,
            'form' => $form->createView(),
            'language_id' => (int) $redirect->getLangId(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $form = $this->formFactory->createNamed(self::UPDATE_FORM, SearchTermRedirectType::class, null, ['include_id' => true]);
        $redirectId = (int) ($request->request->all(self::UPDATE_FORM)['id'] ?? 0);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::SEARCH_TERM_REDIRECT_UPDATE,
            eventFactory: fn (FormInterface $validated): SearchTermRedirectWriteEvent => $this->writeEvent($validated, $this->findOrFail((int) ($validated->getData()['id'] ?? 0))),
            actionLabel: 'Search redirection update',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => null === $this->redirects->find($redirectId)
                ? $this->backToList($this->postedLanguage($request, self::UPDATE_FORM))
                : new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['search_redirect_id' => $redirectId])),
            describeForLog: static fn (SearchTermRedirectWriteEvent $event): array => [\sprintf('Search redirection %s modified', $event->getTerm()), $event->getRedirect()?->getId()],
            successParametersResolver: static fn (SearchTermRedirectWriteEvent $event): array => ['language_id' => (int) $event->getRedirect()?->getLangId()],
            afterDispatch: fn (SearchTermRedirectWriteEvent $event) => $this->warnAboutCommonTerm($request, $event),
        );
    }

    #[Route('/toggle/{search_redirect_id}', name: 'toggle', methods: ['POST'], requirements: ['search_redirect_id' => '\d+'])]
    public function toggle(int $search_redirect_id, Request $request): Response
    {
        $redirect = $this->redirects->find($search_redirect_id);
        if (null === $redirect) {
            return $this->backToList((int) $this->languages->resolve(null)->getId());
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: (new SearchTermRedirectWriteEvent())->setRedirect($redirect),
            eventName: TheliaEvents::SEARCH_TERM_REDIRECT_TOGGLE,
            actionLabel: 'Search redirection activation',
            successRoute: self::LIST_ROUTE,
            successParameters: ['language_id' => (int) $redirect->getLangId()],
            describeForLog: static fn (): array => [\sprintf('Search redirection %s toggled', (string) $redirect->getTerm()), (int) $redirect->getId()],
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $redirect = $this->redirects->find((int) $request->request->get('search_redirect_id', 0));
        if (null === $redirect) {
            return $this->backToList((int) $this->languages->resolve($request->request->get('language_id'))->getId());
        }

        $term = (string) $redirect->getTerm();
        $languageId = (int) $redirect->getLangId();

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: (new SearchTermRedirectWriteEvent())->setRedirect($redirect),
            eventName: TheliaEvents::SEARCH_TERM_REDIRECT_DELETE,
            actionLabel: 'Search redirection deletion',
            successRoute: self::LIST_ROUTE,
            successParameters: ['language_id' => $languageId],
            describeForLog: static fn (): array => [\sprintf('Search redirection %s deleted', $term), null],
        );
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        return $this->importAction->launch($request, SearchDictionaryImportAction::KIND_REDIRECTS, self::LIST_ROUTE);
    }

    private function writeEvent(FormInterface $validated, ?SearchTermRedirect $redirect): SearchTermRedirectWriteEvent
    {
        $data = $validated->getData() ?? [];
        return (new SearchTermRedirectWriteEvent())
            ->setRedirect($redirect)
            ->setLangId((int) ($data['lang_id'] ?? 0))
            ->setTerm((string) ($data['term'] ?? ''))
            ->setTargetType((string) ($data['target_type'] ?? ''))
            ->setTargetId(isset($data['target_id']) ? (int) $data['target_id'] : null)
            ->setUrl(isset($data['url']) ? (string) $data['url'] : null)
            ->setActive((bool) ($data['active'] ?? false));
    }

    private function warnAboutCommonTerm(Request $request, SearchTermRedirectWriteEvent $event): void
    {
        if ($event->isCommonTerm()) {
            $this->flash->add($request, 'warning', $this->translator->trans('This term appears in the title of many products: the redirection will deprive visitors of their results.'));
        }
    }

    private function findOrFail(int $id): SearchTermRedirect
    {
        return $this->redirects->find($id) ?? throw new \LogicException('Search redirection not found');
    }

    private function postedLanguage(Request $request, string $formName): int
    {
        return (int) $this->languages->resolve($request->request->all($formName)['lang_id'] ?? null)->getId();
    }

    private function backToListWithDraft(Request $request, FormInterface $form): RedirectResponse
    {
        $this->drafts->keep($request, $form);

        return $this->backToList($this->postedLanguage($request, self::CREATE_FORM));
    }

    private function backToList(int $languageId): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE, ['language_id' => $languageId]));
    }
}
