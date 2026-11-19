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

namespace BackOfficeDefaultTwigBundle\Service\SearchDictionary;

use BackOfficeDefaultTwigBundle\Repository\SearchTermRedirectRepository;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Search\Dictionary\SearchRedirectTargetType;
use Thelia\Model\SearchTermRedirect;

final readonly class SearchTermRedirectListPresenter
{
    public const SORTABLE_FIELDS = ['term'];

    public function __construct(
        private SearchTermRedirectRepository $redirects,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<SearchTermRedirect> $redirects
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $redirects, string $locale, bool $mayToggle): array
    {
        $idsByType = [];
        foreach ($redirects as $redirect) {
            if (SearchRedirectTargetType::isEntity((string) $redirect->getTargetType()) && null !== $redirect->getTargetId()) {
                $idsByType[(string) $redirect->getTargetType()][] = (int) $redirect->getTargetId();
            }
        }

        $titles = [];
        foreach ($idsByType as $type => $ids) {
            $titles[$type] = $this->redirects->titlesOf($type, array_values(array_unique($ids)), $locale);
        }

        return array_map(fn (SearchTermRedirect $redirect): array => $this->row($redirect, $titles, $mayToggle), $redirects);
    }

    /**
     * @param array<string, array<int, string>> $titles
     *
     * @return array<string, mixed>
     */
    private function row(SearchTermRedirect $redirect, array $titles, bool $mayToggle): array
    {
        $id = (int) $redirect->getId();
        $type = (string) $redirect->getTargetType();

        return [
            'id' => $id,
            'term' => (string) $redirect->getTerm(),
            'target' => $this->target($type, $redirect->getTargetId(), $redirect->getUrl(), $titles[$type] ?? []),
            'active' => 1 === $redirect->getActive(),
            'toggle_url' => $mayToggle ? $this->urls->generate('admin.configuration.search_redirects.toggle', ['search_redirect_id' => $id]) : null,
            '_actions' => [
                new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate('admin.configuration.search_redirects.update', ['search_redirect_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: AdminResources::CONFIG),
                new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#search-redirect-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: AdminResources::CONFIG, dataAttributes: ['search-redirect-id' => $id]),
            ],
        ];
    }

    /**
     * @param array<int, string> $titles
     */
    private function target(string $type, ?int $targetId, ?string $url, array $titles): string
    {
        if (SearchRedirectTargetType::URL === $type) {
            return \sprintf('%s : %s', $this->typeLabel($type), (string) $url);
        }

        return \sprintf('%s : %s', $this->typeLabel($type), $titles[(int) $targetId] ?? '#'.(int) $targetId);
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            SearchRedirectTargetType::PRODUCT => $this->translator->trans('Product'),
            SearchRedirectTargetType::CATEGORY => $this->translator->trans('Category'),
            SearchRedirectTargetType::CONTENT => $this->translator->trans('Content'),
            SearchRedirectTargetType::FOLDER => $this->translator->trans('Folder'),
            SearchRedirectTargetType::BRAND => $this->translator->trans('Brand'),
            default => $this->translator->trans('Shop path'),
        };
    }
}
