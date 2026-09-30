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

use BackOfficeDefaultTwigBundle\Form\Attribute\AttributeAvType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Attribute\AttributeAvCreateEvent;
use Thelia\Core\Event\Attribute\AttributeAvDeleteEvent;
use Thelia\Core\Event\Attribute\AttributeAvUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;

#[Route('/admin/configuration/attributes-av', name: 'admin.configuration.attributes-av.')]
final class AttributeAvController
{
    private const RESOURCE = AdminResources::ATTRIBUTE;
    private const ATTRIBUTE_EDIT_ROUTE = 'admin.configuration.attributes.update';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly EventDispatcherInterface $events,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_attributeav_creation', AttributeAvType::class, null, [
        ]);

        $attributeId = (int) ($request->request->all('thelia_attributeav_creation')['attribute_id'] ?? 0);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::ATTRIBUTE_AV_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Attribute value creation',
            successRoute: self::ATTRIBUTE_EDIT_ROUTE,
            successParameters: ['attribute_id' => $attributeId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::ATTRIBUTE_EDIT_ROUTE, ['attribute_id' => $attributeId])),
        );
    }

    #[Route('/update', name: 'update', methods: ['GET'])]
    public function updateRedirect(Request $request): RedirectResponse
    {
        $attributeId = (int) $request->query->get('attribute_id', 0);

        return new RedirectResponse($this->urls->generate(self::ATTRIBUTE_EDIT_ROUTE, ['attribute_id' => $attributeId]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_attributeav_modification', AttributeAvType::class, null, [
            'include_id' => true,
        ]);

        $attributeId = (int) ($request->request->all('thelia_attributeav_modification')['attribute_id'] ?? 0);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::ATTRIBUTE_AV_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Attribute value update',
            successRoute: self::ATTRIBUTE_EDIT_ROUTE,
            successParameters: ['attribute_id' => $attributeId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::ATTRIBUTE_EDIT_ROUTE, ['attribute_id' => $attributeId])),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $attributeAvId = (int) $request->query->get('attributeav_id', 0);
        $attributeAv = AttributeAvQuery::create()->findPk($attributeAvId);
        $attributeId = $attributeAv?->getAttributeId() ?? 0;

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new AttributeAvDeleteEvent($attributeAvId),
            eventName: TheliaEvents::ATTRIBUTE_AV_DELETE,
            actionLabel: 'Attribute value deletion',
            successRoute: self::ATTRIBUTE_EDIT_ROUTE,
            successParameters: ['attribute_id' => $attributeId],
        );
    }

    #[Route('/update-title/{attribute_av_id}', name: 'update-title', methods: ['POST'], requirements: ['attribute_av_id' => '\d+'])]
    public function updateTitle(Request $request, int $attribute_av_id): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::UPDATE) !== null) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Access denied.')], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Invalid CSRF token.')], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) $request->request->get('title', ''));
        if ($title === '') {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Title cannot be empty.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $av = AttributeAvQuery::create()->findPk($attribute_av_id);
        if ($av === null) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Attribute value not found.')], Response::HTTP_NOT_FOUND);
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? $this->defaultLocale();

        $event = new AttributeAvUpdateEvent($attribute_av_id);
        $event->setTitle($title)->setLocale($locale)->setAttributeId((int) $av->getAttributeId());
        $this->events->dispatch($event, TheliaEvents::ATTRIBUTE_AV_UPDATE);

        return new JsonResponse(['success' => true, 'title' => $title]);
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $attributeAvId = (int) $request->query->get('attributeav_id', 0);
        $attributeAv = AttributeAvQuery::create()->findPk($attributeAvId);
        $attributeId = $attributeAv?->getAttributeId() ?? 0;

        $event = new UpdatePositionEvent(
            $attributeAvId,
            (int) $request->query->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE),
            (int) $request->query->get('position', 0),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ATTRIBUTE_AV_UPDATE_POSITION,
            actionLabel: 'Attribute value reorder',
            successRoute: self::ATTRIBUTE_EDIT_ROUTE,
            successParameters: ['attribute_id' => $attributeId],
        );
    }

    private function createEvent(FormInterface $validated): AttributeAvCreateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new AttributeAvCreateEvent();
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setAttributeId((int) ($data['attribute_id'] ?? 0));

        return $event;
    }

    private function updateEvent(FormInterface $validated): AttributeAvUpdateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new AttributeAvUpdateEvent((int) ($data['id'] ?? 0));
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setAttributeId((int) ($data['attribute_id'] ?? 0));

        return $event;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
