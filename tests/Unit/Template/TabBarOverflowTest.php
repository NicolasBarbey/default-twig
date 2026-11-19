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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every tab bar of the theme hands the tabs that do not fit to the More menu of bo-tab-overflow,
 * with a translated label: a bar left without the controller would let its tabs wrap again.
 */
final class TabBarOverflowTest extends TestCase
{
    public function testEveryTabBarRunsTheOverflowController(): void
    {
        $bars = [];
        $files = (new Finder())->files()->in(\dirname(__DIR__, 3))->exclude(['vendor', 'node_modules', 'tests', 'var', 'public'])->name('*.html.twig');

        foreach ($files as $file) {
            preg_match_all('/<div class="bo-tab-bar"([^>]*)>/', $file->getContents(), $matches);
            foreach ($matches[1] as $attributes) {
                $bars[$file->getRelativePathname()][] = $attributes;
            }
        }

        self::assertNotEmpty($bars, 'The theme has tab bars to check.');
        foreach ($bars as $template => $attributesOfBars) {
            foreach ($attributesOfBars as $attributes) {
                self::assertStringContainsString('data-controller="bo-tab-overflow"', $attributes, $template.': its tab bar runs bo-tab-overflow.');
                self::assertStringContainsString("data-bo-tab-overflow-more-label-value=\"{{ 'More'|trans }}\"", $attributes, $template.': its More menu gets a translated label.');
            }
        }
    }
}
