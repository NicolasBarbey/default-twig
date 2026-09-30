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

namespace BackOfficeDefaultTwigBundle\Controller\Customer;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Customer\CustomerAnonymizeEvent;
use Thelia\Core\Event\Customer\CustomerPersonalDataExportEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Tools\TokenProvider;

/**
 * Answers a data subject request from the back office: download everything the
 * shop knows about one customer, or erase their identity while keeping the
 * accounting record of their orders.
 *
 * Both operations are the core events of Thelia\Domain\Customer\Service; this
 * controller only exposes them, so a module declaring a
 * CustomerPersonalDataProviderInterface is served by the buttons too.
 */
#[Route('/admin', name: 'admin.')]
final class CustomerPersonalDataController
{
    private const RESOURCE = AdminResources::CUSTOMER;
    private const LIST_ROUTE = 'admin.customers';
    private const EDIT_ROUTE = 'admin.customer.update.view';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormAction $action,
        private readonly AdminLogger $adminLogger,
        private readonly EventDispatcherInterface $events,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
    ) {
    }

    /**
     * The archive is built and streamed within the request. Writing it under
     * web/ or in a shared cache directory would leave the personal data of one
     * person behind a URL that outlives the session that asked for it.
     */
    #[Route('/customer/personal-data', name: 'customer.personal_data.export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $customerId = (int) $request->query->get('customer_id', 0);
        $customer = CustomerQuery::create()->findPk($customerId);
        if ($customer === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $customerId]));
        }

        $event = new CustomerPersonalDataExportEvent($customer);
        $this->events->dispatch($event, TheliaEvents::CUSTOMER_PERSONAL_DATA_EXPORT);

        // The message never repeats the identity of the customer: admin_log keeps
        // its rows long after an anonymization, and the audit trail of an erasure
        // must not be what puts the erased name back into the database.
        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::VIEW,
            \sprintf('Personal data of customer ID %d exported', $customerId),
            $customerId,
        );

        return $this->archiveResponse($event->getPersonalData(), $customer);
    }

    #[Route('/customer/anonymize', name: 'customer.anonymize', methods: ['POST'])]
    public function anonymize(Request $request): Response
    {
        $customerId = (int) $request->request->get('customer_id', 0);
        $customer = CustomerQuery::create()->findPk($customerId);
        if ($customer === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new CustomerAnonymizeEvent($customer),
            eventName: TheliaEvents::CUSTOMER_ANONYMIZE,
            actionLabel: 'Customer anonymization',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['customer_id' => $customerId],
            describeForLog: static fn (): array => [\sprintf('Customer ID %d anonymized', $customerId), $customerId],
        );
    }

    /**
     * @param array<string, mixed> $personalData
     */
    private function archiveResponse(array $personalData, Customer $customer): Response
    {
        $json = json_encode(
            $personalData,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        $response = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->archiveFileName($customer),
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    private function archiveFileName(Customer $customer): string
    {
        $reference = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $customer->getRef()) ?? '';
        if ($reference === '') {
            $reference = (string) $customer->getId();
        }

        return \sprintf('personal-data-%s-%s.json', $reference, date('Y-m-d'));
    }
}
