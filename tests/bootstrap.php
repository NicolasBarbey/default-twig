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

use Composer\Autoload\ClassLoader;

// The template is installed inside a Thelia project, four levels down from its
// root: templates/backOffice/default-twig/tests. The project owns the autoloader,
// the environment and the test database, so the suite boots on its bootstrap and
// only adds the namespace of its own test helpers.
$projectDir = dirname(__DIR__, 4);

require $projectDir.'/tests/bootstrap.php';

/** @var ClassLoader $loader */
$loader = require $projectDir.'/vendor/autoload.php';
$loader->addPsr4('BackOfficeDefaultTwigBundle\\Tests\\', __DIR__);
