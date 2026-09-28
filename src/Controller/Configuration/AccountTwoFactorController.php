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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Admin\TwoFactor\AdminTwoFactorManager;
use Thelia\Model\Admin;
use Thelia\Model\AdminQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/account/two-factor', name: 'admin.account.two-factor.')]
final readonly class AccountTwoFactorController
{
    private const VIEW_ROUTE = 'admin.account.two-factor.view';
    private const VIEW_TEMPLATE = '@BackOfficeDefaultTwig/configuration/account/two-factor.html.twig';
    private const BACKUP_CODES_TEMPLATE = '@BackOfficeDefaultTwig/two-factor-backup-codes.html.twig';

    public function __construct(
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private AdminTwoFactorManager $twoFactorManager,
        private TokenProvider $tokens,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'view', methods: ['GET'])]
    public function view(): Response
    {
        $admin = $this->currentAdmin();

        if (!$admin instanceof Admin) {
            return $this->access->check([], [], []) ?? new RedirectResponse($this->urls->generate('admin.login'));
        }

        $enabled = $this->twoFactorManager->isEnabledFor($admin);

        return $this->noStore(new Response($this->twig->render(self::VIEW_TEMPLATE, [
            'two_factor_enabled' => $enabled,
            'two_factor_required' => $this->twoFactorManager->isRequired(),
            'remaining_backup_codes' => $enabled ? $this->twoFactorManager->remainingBackupCodeCount($admin) : 0,
        ])));
    }

    #[Route('/backup-codes', name: 'regenerate', methods: ['POST'])]
    public function regenerateBackupCodes(Request $request): Response
    {
        $admin = $this->currentAdmin();

        if (!$admin instanceof Admin || !$this->hasValidToken($request)) {
            return $this->backToView($request, $this->translator->trans('Your session has expired. Please try again.'));
        }

        if (!$this->twoFactorManager->isEnabledFor($admin)) {
            return $this->backToView($request, $this->translator->trans('Two-step verification is not enabled on your account.'));
        }

        return $this->noStore(new Response($this->twig->render(self::BACKUP_CODES_TEMPLATE, [
            'backup_codes' => $this->twoFactorManager->regenerateBackupCodes($admin),
            'continue_url' => $this->urls->generate(self::VIEW_ROUTE),
        ])));
    }

    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $admin = $this->currentAdmin();

        if (!$admin instanceof Admin || !$this->hasValidToken($request)) {
            return $this->backToView($request, $this->translator->trans('Your session has expired. Please try again.'));
        }

        $storedAdmin = AdminQuery::create()->findPk($admin->getId());
        $storedAdmin?->reload();

        if (!$storedAdmin instanceof Admin || !$storedAdmin->checkPassword((string) $request->request->get('password', ''))) {
            return $this->backToView($request, $this->translator->trans('This password is not the one of your account.'));
        }

        $this->twoFactorManager->disable($storedAdmin);
        $this->flash($request, 'success', $this->translator->trans('Two-step verification is disabled on your account.'));

        return new RedirectResponse($this->urls->generate(self::VIEW_ROUTE));
    }

    private function currentAdmin(): ?Admin
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin : null;
    }

    private function hasValidToken(Request $request): bool
    {
        try {
            return $this->tokens->checkToken((string) ($request->request->get('_token') ?? $request->query->get('_token') ?? ''));
        } catch (\Throwable) {
            return false;
        }
    }

    private function backToView(Request $request, string $message): RedirectResponse
    {
        $this->flash($request, 'danger', $message);

        return new RedirectResponse($this->urls->generate(self::VIEW_ROUTE));
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session !== null && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
