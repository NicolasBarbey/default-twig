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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Service\CatalogPriceRule\CatalogPriceRuleListPresenter;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Domain\Pricing\Rule\RuleRepricer;
use Thelia\Model\CatalogPriceRule;
use Thelia\Test\IntegrationTestCase;

/**
 * The rule list reads its rows, states and counts in a fixed number of statements,
 * whatever the number of rules: a list screen asking one query per row is what the
 * budget below refuses.
 */
final class CatalogPriceRuleListTest extends IntegrationTestCase
{
    public function testTheWholeListIsBuiltInAFixedNumberOfQueries(): void
    {
        $factory = $this->createFixtureFactory();
        $currency = $factory->currency();
        $category = $factory->category();
        $product = $factory->product($category, $factory->taxRule(), $currency);
        $repricer = $this->getService(RuleRepricer::class);

        $rules = [];

        for ($i = 0; $i < 5; ++$i) {
            $rule = $factory->catalogPriceRule(['active' => true, 'percentageValue' => 10.0 + $i, 'title' => 'List rule '.$i]);
            $factory->catalogPriceRuleCriterion($rule, CatalogPriceRule::CRITERION_PRODUCT, $product->getId());
            $repricer->afterRuleChanged($rule);
            $rules[] = $rule;
        }

        $presenter = $this->getService(CatalogPriceRuleListPresenter::class);
        $rows = [];
        $queries = QueryCounter::count(static function () use (&$rows, $presenter): void {
            $rows = $presenter->build('en_US');
        });

        self::assertLessThanOrEqual(6, $queries, 'One fixed set of statements for the page, whatever the number of rules.');

        $byId = array_column($rows, null, 'id');

        foreach ($rules as $rule) {
            self::assertArrayHasKey($rule->getId(), $byId);
            self::assertSame(1, $byId[$rule->getId()]['product_count']);
            self::assertSame(CatalogPriceRule::STATE_RUNNING, $byId[$rule->getId()]['state']);
        }
    }
}
