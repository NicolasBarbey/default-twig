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
use Symfony\Component\Routing\RouterInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\Module;

final readonly class ModuleCapabilityChecker
{
    /** Generic fallback route that matches every module code — not a real configuration page. */
    private const GENERIC_CONFIGURE_ROUTE = 'admin.module.configure';

    public function __construct(
        private ModuleRepository $modules,
        private RouterInterface $router,
    ) {
    }

    public function isHookable(Module $module): bool
    {
        return ($this->modules->countHooksByModule()[(int) $module->getId()] ?? 0) > 0;
    }

    public function isConfigurable(Module $module): bool
    {
        if (!$module->getActivate()) {
            return false;
        }

        $configurationHooks = $this->modules->countActiveConfigurationHooksByModule(TemplateDefinition::BACK_OFFICE);

        if (($configurationHooks[(int) $module->getId()] ?? 0) > 0) {
            return true;
        }

        return $this->moduleAdminRouteExists((string) $module->getCode());
    }

    private function moduleAdminRouteExists(string $code): bool
    {
        try {
            $match = $this->router->match('/admin/module/'.$code);
        } catch (\Throwable) {
            return false;
        }

        // The generic ModuleConfigureController route matches every module code, so it would mark
        // every active module as configurable. Only a route declared by the module itself counts.
        return ($match['_route'] ?? null) !== self::GENERIC_CONFIGURE_ROUTE;
    }
}
