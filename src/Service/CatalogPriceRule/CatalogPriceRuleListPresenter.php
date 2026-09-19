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

namespace BackOfficeDefaultTwigBundle\Service\CatalogPriceRule;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Pricing\Rule\Overview\CatalogPriceRuleOverviewQuery;
use Thelia\Model\CatalogPriceRule;
use Thelia\Tools\TokenProvider;

/**
 * The rows of the rule list, from the core overview: one fixed set of statements for
 * the whole page, whatever the number of rules.
 */
final readonly class CatalogPriceRuleListPresenter
{
    private const STATE_COLORS = [
        CatalogPriceRule::STATE_RUNNING => 'success',
        CatalogPriceRule::STATE_SCHEDULED => 'info',
        CatalogPriceRule::STATE_EXPIRED => 'secondary',
        CatalogPriceRule::STATE_INACTIVE => 'secondary',
    ];

    public function __construct(
        private CatalogPriceRuleOverviewQuery $overview,
        private UrlGeneratorInterface $urls,
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(string $locale, string $sortField = 'priority', string $sortDirection = 'asc'): array
    {
        $rows = [];

        foreach ($this->overview->overview(null, null, $locale) as $line) {
            $rule = $line->rule;
            $rule->setLocale($locale);
            $id = (int) $rule->getId();
            $rows[] = [
                'id' => $id,
                'title' => (string) $rule->getTitle(),
                'state' => $line->state,
                'state_label' => $this->stateLabel($line->state),
                'state_color' => self::STATE_COLORS[$line->state] ?? 'secondary',
                'active' => (bool) $rule->getActive(),
                'priority' => (int) $rule->getPriority(),
                'start_date' => $rule->getStartDate('Y-m-d H:i'),
                'end_date' => $rule->getEndDate('Y-m-d H:i'),
                'effect_label' => $this->effectLabel($rule),
                'reserved' => $rule->isReserved(),
                'customer_count' => $line->customerCount,
                'product_count' => $line->productCount,
                'sale_element_count' => $line->saleElementCount,
                'dirty' => (bool) $rule->getDirty(),
                'unknown_criterion_types' => $line->unknownCriterionTypes,
                'edit_url' => $this->urls->generate('admin.catalog-price-rule.update', ['rule_id' => $id]),
                'toggle_url' => $this->tokenizedUrl('admin.catalog-price-rule.toggle', ['rule_id' => $id]),
            ];
        }

        usort($rows, static function (array $left, array $right) use ($sortField, $sortDirection): int {
            $comparison = match ($sortField) {
                'title' => strcasecmp((string) $left['title'], (string) $right['title']),
                'start_date' => strcmp((string) $left['start_date'], (string) $right['start_date']),
                'end_date' => strcmp((string) $left['end_date'], (string) $right['end_date']),
                'state' => strcmp($left['state'], $right['state']),
                'products' => $left['product_count'] <=> $right['product_count'],
                default => [$left['priority'], $left['id']] <=> [$right['priority'], $right['id']],
            };

            return 'desc' === $sortDirection ? -$comparison : $comparison;
        });

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function globalActions(): array
    {
        return [
            'recompute_url' => $this->tokenizedUrl('admin.catalog-price-rule.recompute', []),
            'delete_url' => $this->urls->generate('admin.catalog-price-rule.delete'),
            'delete_token' => $this->tokens->assignToken(),
        ];
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            CatalogPriceRule::STATE_RUNNING => $this->translator->trans('Running'),
            CatalogPriceRule::STATE_SCHEDULED => $this->translator->trans('Scheduled'),
            CatalogPriceRule::STATE_EXPIRED => $this->translator->trans('Expired'),
            default => $this->translator->trans('Inactive'),
        };
    }

    public function effectLabel(CatalogPriceRule $rule): string
    {
        return match ((int) $rule->getEffectType()) {
            CatalogPriceRule::EFFECT_TYPE_AMOUNT => $this->translator->trans('Amount off'),
            CatalogPriceRule::EFFECT_TYPE_FIXED_PRICE => $this->translator->trans('Fixed price'),
            default => $this->translator->trans('%percent%% off', ['%percent%' => rtrim(rtrim(number_format((float) $rule->getPercentageValue(), 2, '.', ''), '0'), '.')]),
        };
    }

    private function tokenizedUrl(string $route, array $parameters): string
    {
        $url = $this->urls->generate($route, $parameters);

        return $url.(str_contains($url, '?') ? '&' : '?').'_token='.$this->tokens->assignToken();
    }
}
