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

namespace BackOfficeDefaultTwigBundle\Service\Module;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\Hook;
use Thelia\Model\HookQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleHook;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;

/**
 * Builds the "Module hooks" page data grouped by hook, mirroring the legacy
 * Smarty module-hooks layout. Front-office hooks (type 1) are excluded because
 * the Flexy front no longer relies on the Smarty hook system.
 */
final readonly class ModuleHookListPresenter
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{
     *     id: int,
     *     code: string,
     *     title: string,
     *     type: int,
     *     type_label: string,
     *     block: bool,
     *     by_module: bool,
     *     description: string,
     *     modules: list<array<string, mixed>>
     * }>
     */
    public function build(string $locale): array
    {
        $modulesById = $this->modulesById($locale);

        $hooks = HookQuery::create()
            ->filterByType(TemplateDefinition::FRONT_OFFICE, \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)
            ->orderByType()
            ->orderByCode()
            ->find();

        $groups = [];
        foreach ($hooks as $hook) {
            \assert($hook instanceof Hook);
            $hook->setLocale($locale);
            $hookId = (int) $hook->getId();
            $byModule = (bool) $hook->getByModule();
            $hookActive = (bool) $hook->getActivate();

            $moduleRows = [];
            $moduleHooks = ModuleHookQuery::create()
                ->filterByHookId($hookId)
                ->orderByPosition()
                ->find();

            foreach ($moduleHooks as $moduleHook) {
                \assert($moduleHook instanceof ModuleHook);
                $module = $modulesById[(int) $moduleHook->getModuleId()] ?? null;
                $moduleActive = $module !== null && (bool) $module->getActivate();
                $moduleHookId = (int) $moduleHook->getId();

                $moduleRows[] = [
                    'id' => $moduleHookId,
                    'module_id' => (int) $moduleHook->getModuleId(),
                    'module_code' => $module !== null ? (string) $module->getCode() : '-',
                    'module_title' => $module !== null ? (string) $module->getTitle() : '-',
                    'classname' => (string) $moduleHook->getClassname(),
                    'method' => (string) $moduleHook->getMethod(),
                    'active' => (bool) $moduleHook->getActive(),
                    'position' => (int) $moduleHook->getPosition(),
                    'module_active' => $moduleActive,
                    'hook_active' => $hookActive,
                    'can_toggle' => $moduleActive && $hookActive && !$byModule,
                    'edit_url' => $this->urls->generate('admin.module-hook.update', ['module_hook_id' => $moduleHookId]),
                    'toggle_url' => $this->urls->generate('admin.module-hook.toggle-activation', ['module_hook_id' => $moduleHookId]),
                    'delete_url' => $this->urls->generate('admin.module-hook.delete'),
                ];
            }

            $groups[] = [
                'id' => $hookId,
                'code' => (string) $hook->getCode(),
                'title' => (string) $hook->getTitle(),
                'type' => (int) $hook->getType(),
                'type_label' => $this->typeLabel((int) $hook->getType()),
                'block' => (bool) $hook->getBlock(),
                'by_module' => $byModule,
                'description' => (string) $hook->getDescription(),
                'modules' => $moduleRows,
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, Module>
     */
    private function modulesById(string $locale): array
    {
        $byId = [];
        foreach (ModuleQuery::create()->orderByCode()->find() as $module) {
            \assert($module instanceof Module);
            $module->setLocale($locale);
            $byId[(int) $module->getId()] = $module;
        }

        return $byId;
    }

    private function typeLabel(int $type): string
    {
        return match ($type) {
            TemplateDefinition::BACK_OFFICE => $this->translator->trans('Back Office'),
            TemplateDefinition::PDF => $this->translator->trans('PDF'),
            TemplateDefinition::EMAIL => $this->translator->trans('Email'),
            default => $this->translator->trans('Other'),
        };
    }
}
