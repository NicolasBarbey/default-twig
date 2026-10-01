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

namespace BackOfficeDefaultTwigBundle\Tests\Support\Kernel;

use App\Kernel;
use BackOfficeDefaultTwigBundle\Tests\Support\Routing\AdminRouteConditionProbe;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The application kernel plus one back-office route whose condition calls a
 * service, the way a module declares one.
 *
 * It compiles into a cache directory of its own, so the extra route never
 * reaches the container and the routes the other tests run against.
 */
final class AdminRouteConditionKernel extends Kernel
{
    public const string ROUTE_PATH = '/admin/route-condition-probe';

    /**
     * Symfony looks for the project from the file of the running kernel class,
     * which sits inside this template, next to its own composer.json: point it
     * back at the shop the application kernel belongs to.
     */
    public function getProjectDir(): string
    {
        return \dirname(self::applicationKernelFile(), 2);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().\DIRECTORY_SEPARATOR.'admin_route_condition_kernel';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container->withPath(self::applicationKernelFile()));

        $container->services()
            ->set(AdminRouteConditionProbe::class)
            ->public()
            ->tag('routing.condition_service', ['alias' => AdminRouteConditionProbe::ALIAS])
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes->withPath(self::applicationKernelFile()));

        $routes->add('admin_route_condition_probe', self::ROUTE_PATH)
            ->controller(AdminRouteConditionProbe::class)
            ->condition(\sprintf("service('%s').allows(request)", AdminRouteConditionProbe::ALIAS));
    }

    /**
     * The application kernel imports the configuration of the shop by paths
     * relative to its own file, which a configurator resolves against the file
     * of the running kernel class: handing it that file keeps them pointing at
     * the shop.
     */
    private static function applicationKernelFile(): string
    {
        return (string) (new \ReflectionClass(Kernel::class))->getFileName();
    }
}
