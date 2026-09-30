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

use BackOfficeDefaultTwigBundle\Repository\ModuleRepository;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Module;
use Thelia\Module\BaseModule;

final readonly class ModuleListPresenter
{
    public function __construct(
        private ModuleRepository $modules,
        private ModuleCapabilityChecker $capabilities,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildListContext(string $locale): array
    {
        $labels = $this->typeLabels();
        $modules = [];
        $typeCount = [];

        foreach ($this->modules->findAllOrderedByPosition($locale) as $module) {
            $row = $this->moduleToRow($module);
            $modules[] = $row;
            $typeCount[$row['type_slug']] = ($typeCount[$row['type_slug']] ?? 0) + 1;
        }

        // Default view order: a single alphabetical list is the most scannable for a unified
        // list. The client-side sort control re-orders in place (A->Z / newest / status).
        usort($modules, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $typeGroups = [];
        foreach ($labels as $slug => $label) {
            $typeGroups[] = ['slug' => $slug, 'label' => $label, 'count' => $typeCount[$slug] ?? 0];
        }
        // Surface any unmapped type ("other") that actually has modules.
        foreach ($typeCount as $slug => $count) {
            if (!isset($labels[$slug])) {
                $typeGroups[] = ['slug' => $slug, 'label' => $this->translator->trans('Other modules'), 'count' => $count];
            }
        }

        $activeCount = \count(array_filter($modules, static fn (array $row): bool => $row['activated']));

        return [
            'modules' => $modules,
            'type_groups' => $typeGroups,
            'total_count' => \count($modules),
            'active_count' => $activeCount,
            'inactive_count' => \count($modules) - $activeCount,
            'allow_module_zip_install' => (bool) (int) ConfigQuery::read('allow_module_zip_install', 0),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeLabels(): array
    {
        return [
            'delivery' => $this->translator->trans('Delivery'),
            'payment' => $this->translator->trans('Payment'),
            'classic' => $this->translator->trans('Classic'),
        ];
    }

    private function typeSlug(int $type): string
    {
        return match ($type) {
            BaseModule::DELIVERY_MODULE_TYPE => 'delivery',
            BaseModule::PAYMENT_MODULE_TYPE => 'payment',
            BaseModule::CLASSIC_MODULE_TYPE => 'classic',
            default => 'other',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleToRow(Module $module): array
    {
        $id = (int) $module->getId();
        $code = (string) $module->getCode();
        $title = (string) $module->getTitle();
        $version = (string) $module->getVersion();
        $activated = (bool) $module->getActivate();
        $type = (int) $module->getType();
        $typeSlug = $this->typeSlug($type);
        $mandatory = ((int) $module->getMandatory()) === 1;
        $description = $this->shortDescription($module);
        $configurable = $activated && $this->capabilities->isConfigurable($module);

        // The module code is the identity (the "name"); the human title becomes the
        // descriptive line beside it. The chapo stays searchable but off the row.
        $caption = ($title !== '' && $title !== $code) ? $title : '';

        return [
            'id' => $id,
            'code' => $code,
            'name' => $code,
            'caption' => $caption,
            'type' => $type,
            'type_slug' => $typeSlug,
            'version' => $version,
            'icon' => $this->iconForType($type),
            'activated' => $activated,
            'mandatory' => $mandatory,
            'configurable' => $configurable,
            // Clicking the name goes where admins actually go: the module settings.
            // Modules without a configuration page fall back to their info form.
            'name_url' => $configurable
                ? $this->urls->generate('admin.module.configure', ['module_code' => $code])
                : $this->urls->generate('admin.module.update', ['module_id' => $id]),
            'toggle_url' => $this->toggleActivationUrl($id, $mandatory, $activated),
            'search' => mb_strtolower(trim($code.' '.$title.' '.$version.' '.$description)),
            'actions' => $this->buildRowActions($module, $id, $code, $type, $activated, $mandatory),
        ];
    }

    /** A monochrome glyph hinting at the module type — shape only, no semantic color. */
    private function iconForType(int $type): string
    {
        return match ($type) {
            BaseModule::DELIVERY_MODULE_TYPE => 'bi-truck',
            BaseModule::PAYMENT_MODULE_TYPE => 'bi-credit-card',
            BaseModule::CLASSIC_MODULE_TYPE => 'bi-puzzle',
            default => 'bi-box',
        };
    }

    private function shortDescription(Module $module): string
    {
        $raw = (string) ($module->getChapo() ?: $module->getDescription() ?: '');
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($raw)));

        return $text;
    }

    private function toggleActivationUrl(int $id, bool $mandatory, bool $activated): ?string
    {
        if ($mandatory && $activated) {
            return null;
        }

        return $this->urls->generate('admin.module.toggle-activation', ['module_id' => $id]);
    }

    /**
     * @return list<RowAction>
     */
    private function buildRowActions(Module $module, int $id, string $code, int $type, bool $activated, bool $mandatory): array
    {
        $actions = [];

        if ($activated && $this->capabilities->isConfigurable($module)) {
            $actions[] = new RowAction(
                kind: 'config',
                label: $this->translator->trans('Configure this module'),
                href: $this->urls->generate('admin.module.configure', ['module_code' => $code]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: $code,
            );
        }

        // Kept inline next to the gear: the name now opens the settings, so editing
        // the module info needs its own visible entry point.
        $actions[] = new RowAction(
            kind: 'edit',
            label: $this->translator->trans('Edit module info'),
            href: $this->urls->generate('admin.module.update', ['module_id' => $id]),
            grantedAttribute: AccessManager::UPDATE,
            grantedSubject: AdminResources::MODULE,
        );

        $actions[] = new RowAction(
            kind: 'info',
            label: $this->translator->trans('Module information'),
            modalTarget: '#module-information-modal',
            grantedAttribute: AccessManager::VIEW,
            grantedSubject: AdminResources::MODULE,
            dataAttributes: ['fetch-url' => $this->urls->generate('admin.module.information', ['module_id' => $id])],
            inMenu: true,
        );

        $actions[] = new RowAction(
            kind: 'doc',
            label: $this->translator->trans('Module documentation'),
            modalTarget: '#module-documentation-modal',
            grantedAttribute: AccessManager::VIEW,
            grantedSubject: AdminResources::MODULE,
            dataAttributes: ['fetch-url' => $this->urls->generate('admin.module.documentation', ['module_id' => $id])],
            inMenu: true,
        );

        if ($activated && $this->capabilities->isHookable($module)) {
            $actions[] = new RowAction(
                kind: 'hook',
                label: $this->translator->trans('Manage its hooks'),
                href: $this->urls->generate('admin.module-hook', ['module' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::MODULE_HOOK,
                inMenu: true,
            );
        }

        if ($type === BaseModule::DELIVERY_MODULE_TYPE) {
            $actions[] = new RowAction(
                kind: 'shipping-zones',
                label: $this->translator->trans('Shipping zones'),
                href: $this->urls->generate('admin.configuration.shipping-zones.update.view', ['delivery_module_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::AREA,
                inMenu: true,
            );
        }

        if (!$mandatory) {
            // A module an order was placed with can be deleted: the order keeps the name the
            // module carried and releases its reference.
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete this module'),
                modalTarget: '#module-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: AdminResources::MODULE,
                dataAttributes: ['module-id' => $id, 'module-label' => (string) $module->getTitle()],
                inMenu: true,
            );
        }

        return $actions;
    }
}
