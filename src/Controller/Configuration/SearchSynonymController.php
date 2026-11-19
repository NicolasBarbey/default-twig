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

use BackOfficeDefaultTwigBundle\Form\Configuration\SearchSynonymType;
use BackOfficeDefaultTwigBundle\Repository\SearchSynonymRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\SearchDictionary\SearchDictionaryImportAction;
use BackOfficeDefaultTwigBundle\Service\SearchDictionary\SearchDictionaryLanguage;
use BackOfficeDefaultTwigBundle\Service\SearchDictionary\SearchSynonymListPresenter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Search\Event\SearchSynonymWriteEvent;
use Thelia\Model\SearchSynonym;
use Twig\Environment;

#[Route('/admin/configuration/search-synonyms', name: 'admin.configuration.search_synonyms.')]
final class SearchSynonymController
{
    private const RESOURCE = AdminResources::CONFIG;
    private const LIST_ROUTE = 'admin.configuration.search_synonyms.default';
    private const EDIT_ROUTE = 'admin.configuration.search_synonyms.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/search_synonym/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/search_synonym/edit.html.twig';
    private const CREATE_FORM = 'thelia_search_synonym_create';
    private const UPDATE_FORM = 'thelia_search_synonym_update';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly SearchSynonymRepository $synonyms,
        private readonly SearchSynonymListPresenter $presenter,
        private readonly SearchDictionaryLanguage $languages,
        private readonly SearchDictionaryImportAction $importAction,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $language = $this->languages->resolve($request->query->get('language_id'));
        $sort = ListSort::fromRequest($request, SearchSynonymListPresenter::SORTABLE_FIELDS, 'term');
        $page = $this->synonyms->page((int) $language->getId(), $sort->direction, (int) $request->query->get('page', 1));

        $createForm = $this->formFactory->createNamed(self::CREATE_FORM, SearchSynonymType::class, [
            'lang_id' => (int) $language->getId(),
            'bidirectional' => false,
            'active' => true,
        ]);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->presenter->rows($page['items'], $this->access->can(self::RESOURCE, AccessManager::UPDATE)),
            'create_form' => $createForm->createView(),
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
        $form = $this->formFactory->createNamed(self::CREATE_FORM, SearchSynonymType::class);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::SEARCH_SYNONYM_CREATE,
            eventFactory: fn (FormInterface $validated): SearchSynonymWriteEvent => $this->writeEvent($validated, null),
            actionLabel: 'Search synonym creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => $this->backToList($this->postedLanguage($request, self::CREATE_FORM)),
            describeForLog: static fn (SearchSynonymWriteEvent $event): array => [\sprintf('Search synonym %s created', $event->getTerm()), $event->getSynonym()?->getId()],
            successParametersResolver: static fn (SearchSynonymWriteEvent $event): array => ['language_id' => (int) $event->getSynonym()?->getLangId()],
        );
    }

    #[Route('/update/{search_synonym_id}', name: 'update', methods: ['GET'], requirements: ['search_synonym_id' => '\d+'])]
    public function updateView(int $search_synonym_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $synonym = $this->synonyms->find($search_synonym_id);
        if (null === $synonym) {
            return $this->backToList((int) $this->languages->resolve(null)->getId());
        }

        $form = $this->formFactory->createNamed(self::UPDATE_FORM, SearchSynonymType::class, [
            'id' => $synonym->getId(),
            'lang_id' => $synonym->getLangId(),
            'term' => $synonym->getTerm(),
            'synonyms' => implode("\n", SearchSynonymListPresenter::equivalents($synonym)),
            'bidirectional' => 1 === $synonym->getBidirectional(),
            'active' => 1 === $synonym->getActive(),
        ], ['include_id' => true]);

        $response = new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'synonym' => $synonym,
            'form' => $form->createView(),
            'language_id' => (int) $synonym->getLangId(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $form = $this->formFactory->createNamed(self::UPDATE_FORM, SearchSynonymType::class, null, ['include_id' => true]);
        $synonymId = (int) ($request->request->all(self::UPDATE_FORM)['id'] ?? 0);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::SEARCH_SYNONYM_UPDATE,
            eventFactory: fn (FormInterface $validated): SearchSynonymWriteEvent => $this->writeEvent($validated, $this->findOrFail((int) ($validated->getData()['id'] ?? 0))),
            actionLabel: 'Search synonym update',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => null === $this->synonyms->find($synonymId)
                ? $this->backToList($this->postedLanguage($request, self::UPDATE_FORM))
                : new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['search_synonym_id' => $synonymId])),
            describeForLog: static fn (SearchSynonymWriteEvent $event): array => [\sprintf('Search synonym %s modified', $event->getTerm()), $event->getSynonym()?->getId()],
            successParametersResolver: static fn (SearchSynonymWriteEvent $event): array => ['language_id' => (int) $event->getSynonym()?->getLangId()],
        );
    }

    #[Route('/toggle/{search_synonym_id}', name: 'toggle', methods: ['POST'], requirements: ['search_synonym_id' => '\d+'])]
    public function toggle(int $search_synonym_id, Request $request): Response
    {
        $synonym = $this->synonyms->find($search_synonym_id);
        if (null === $synonym) {
            return $this->backToList((int) $this->languages->resolve(null)->getId());
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: (new SearchSynonymWriteEvent())->setSynonym($synonym),
            eventName: TheliaEvents::SEARCH_SYNONYM_TOGGLE,
            actionLabel: 'Search synonym activation',
            successRoute: self::LIST_ROUTE,
            successParameters: ['language_id' => (int) $synonym->getLangId()],
            describeForLog: static fn (): array => [\sprintf('Search synonym %s toggled', (string) $synonym->getTerm()), (int) $synonym->getId()],
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $synonym = $this->synonyms->find((int) $request->request->get('search_synonym_id', 0));
        if (null === $synonym) {
            return $this->backToList((int) $this->languages->resolve($request->request->get('language_id'))->getId());
        }

        $term = (string) $synonym->getTerm();
        $languageId = (int) $synonym->getLangId();

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: (new SearchSynonymWriteEvent())->setSynonym($synonym),
            eventName: TheliaEvents::SEARCH_SYNONYM_DELETE,
            actionLabel: 'Search synonym deletion',
            successRoute: self::LIST_ROUTE,
            successParameters: ['language_id' => $languageId],
            describeForLog: static fn (): array => [\sprintf('Search synonym %s deleted', $term), null],
        );
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        return $this->importAction->launch($request, SearchDictionaryImportAction::KIND_SYNONYMS, self::LIST_ROUTE);
    }

    private function writeEvent(FormInterface $validated, ?SearchSynonym $synonym): SearchSynonymWriteEvent
    {
        $data = $validated->getData() ?? [];
        $lines = preg_split('/\R/', (string) ($data['synonyms'] ?? '')) ?: [];

        return (new SearchSynonymWriteEvent())
            ->setSynonym($synonym)
            ->setLangId((int) ($data['lang_id'] ?? 0))
            ->setTerm((string) ($data['term'] ?? ''))
            ->setSynonyms(array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => '' !== $line)))
            ->setBidirectional((bool) ($data['bidirectional'] ?? false))
            ->setActive((bool) ($data['active'] ?? false));
    }

    private function findOrFail(int $id): SearchSynonym
    {
        return $this->synonyms->find($id) ?? throw new \LogicException('Search synonym not found');
    }

    private function postedLanguage(Request $request, string $formName): int
    {
        return (int) $this->languages->resolve($request->request->all($formName)['lang_id'] ?? null)->getId();
    }

    private function backToList(int $languageId): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE, ['language_id' => $languageId]));
    }
}
