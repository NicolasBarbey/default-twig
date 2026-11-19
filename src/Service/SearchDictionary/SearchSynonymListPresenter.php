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

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\SearchSynonym;

final readonly class SearchSynonymListPresenter
{
    public const SORTABLE_FIELDS = ['term'];

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<SearchSynonym> $synonyms
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $synonyms, bool $mayToggle): array
    {
        return array_map(fn (SearchSynonym $synonym): array => $this->row($synonym, $mayToggle), $synonyms);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SearchSynonym $synonym, bool $mayToggle): array
    {
        $id = (int) $synonym->getId();

        return [
            'id' => $id,
            'term' => (string) $synonym->getTerm(),
            'synonyms' => implode(', ', self::equivalents($synonym)),
            'direction' => 1 === $synonym->getBidirectional() ? $this->translator->trans('Both directions') : $this->translator->trans('One direction'),
            'active' => 1 === $synonym->getActive(),
            'toggle_url' => $mayToggle ? $this->urls->generate('admin.configuration.search_synonyms.toggle', ['search_synonym_id' => $id]) : null,
            '_actions' => [
                new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate('admin.configuration.search_synonyms.update', ['search_synonym_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: AdminResources::CONFIG),
                new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#search-synonym-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: AdminResources::CONFIG, dataAttributes: ['search-synonym-id' => $id]),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function equivalents(SearchSynonym $synonym): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $synonym->getSynonyms())), static fn (string $line): bool => '' !== $line));
    }
}
