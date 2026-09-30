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

namespace BackOfficeDefaultTwigBundle\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BackOfficeUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly TokenProvider $tokens,
        private readonly RequestStack $requestStack,
        #[Autowire(param: 'thelia.token_id')]
        private readonly string $tokenId = 'thelia.token_provider',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('assignToken', $this->assignToken(...)),
            new TwigFunction('bo_languages', $this->boLanguages(...)),
            new TwigFunction('bo_current_language', $this->boCurrentLanguage(...)),
        ];
    }

    /** @return list<Lang> */
    public function boLanguages(): array
    {
        return array_values(iterator_to_array(
            LangQuery::create()->filterByActive(true)->orderByPosition()->find(),
        ));
    }

    public function boCurrentLanguage(): Lang
    {
        $session = $this->requestStack->getMainRequest()?->getSession();

        if ($session instanceof TheliaSession) {
            return $session->getAdminLang();
        }

        return Lang::getDefaultLanguage();
    }

    public function assignToken(): string
    {
        // Reuse the per-session token if one already exists, so concurrent requests
        // (e.g. the image/document list-ajax fragments fired on load) don't regenerate
        // it and invalidate the GET links/forms already rendered on the page.
        $session = $this->requestStack->getMainRequest()?->getSession();
        if ($session instanceof SessionInterface) {
            $existing = $session->get($this->tokenId);
            if (\is_string($existing) && $existing !== '') {
                return $existing;
            }
        }

        return $this->tokens->assignToken() ?? '';
    }
}
