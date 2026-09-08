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

use BackOfficeDefaultTwigBundle\Repository\ModuleRepository;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The module list, which answers two questions per row: does this module have a
 * configuration page, and does it have hooks to manage. Both were one count query
 * per module, and the first one was asked twice per row.
 */
final class ModuleListTest extends IntegrationTestCase
{
    private ModuleRepository $modules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modules = new ModuleRepository();
    }

    public function testTheHookCountsOfEveryModuleCostOneQueryEach(): void
    {
        $hooks = [];
        $configurationHooks = [];

        $queries = QueryCounter::count(function () use (&$hooks, &$configurationHooks): void {
            $hooks = $this->modules->countHooksByModule();
            $configurationHooks = $this->modules->countActiveConfigurationHooksByModule(TemplateDefinition::BACK_OFFICE);
        });

        self::assertNotSame([], $hooks, 'The seeded shop has modules with hooks.');
        self::assertSame(2, $queries, 'One grouped count per question, not one per module.');
        self::assertSame([], array_filter($configurationHooks, static fn (int $count): bool => $count < 1));
    }

    public function testTheSecondReaderOfTheSameRequestPaysNothing(): void
    {
        $this->modules->countHooksByModule();
        $this->modules->countActiveConfigurationHooksByModule(TemplateDefinition::BACK_OFFICE);

        $queries = QueryCounter::count(function (): void {
            // What the list does per row: the configuration question is asked once
            // for the row and once again to build its actions.
            foreach (ModuleQuery::create()->find() as $ignored) {
                $this->modules->countHooksByModule();
                $this->modules->countActiveConfigurationHooksByModule(TemplateDefinition::BACK_OFFICE);
                $this->modules->countActiveConfigurationHooksByModule(TemplateDefinition::BACK_OFFICE);
            }
        });

        self::assertSame(1, $queries, 'Only the module list itself is read again.');
    }

    public function testTheHookCountsMatchAModuleByModuleCount(): void
    {
        $expected = [];
        foreach (ModuleQuery::create()->orderById()->find() as $module) {
            $count = ModuleHookQuery::create()->filterByModuleId((int) $module->getId())->count();
            if ($count > 0) {
                $expected[(int) $module->getId()] = $count;
            }
        }

        $counts = $this->modules->countHooksByModule();
        ksort($counts);

        self::assertSame($expected, $counts);
    }

    public function testTheConfigurationHookCountsMatchAModuleByModuleCount(): void
    {
        $expected = [];
        foreach (ModuleQuery::create()->orderById()->find() as $module) {
            $count = ModuleHookQuery::create()
                ->filterByModuleId((int) $module->getId())
                ->filterByActive(true)
                ->useHookQuery()
                    ->filterByCode('module.configuration')
                    ->filterByType(TemplateDefinition::BACK_OFFICE)
                ->endUse()
                ->count();
            if ($count > 0) {
                $expected[(int) $module->getId()] = $count;
            }
        }

        $counts = $this->modules->countActiveConfigurationHooksByModule(TemplateDefinition::BACK_OFFICE);
        ksort($counts);

        self::assertSame($expected, $counts);
    }

    public function testTheModuleListReadsItsTitlesWithTheModules(): void
    {
        $titles = [];

        $queries = QueryCounter::count(function () use (&$titles): void {
            foreach ($this->modules->findAllOrderedByPosition('en_US') as $module) {
                $titles[] = (string) $module->getTitle();
            }
        });

        self::assertNotSame([], $titles);
        self::assertSame(1, $queries, 'The list and its titles are one joined query.');
    }
}
