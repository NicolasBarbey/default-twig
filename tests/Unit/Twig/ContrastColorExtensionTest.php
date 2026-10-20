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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Twig;

use BackOfficeDefaultTwigBundle\Twig\ContrastColorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/** A status badge takes whichever of dark or white text contrasts more with its colour. */
final class ContrastColorExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function seededStatusColors(): iterable
    {
        yield 'unpaid red' => ['#dc3545', '#ffffff'];
        yield 'paid green' => ['#5cb85c', '#212529'];
        yield 'processing orange' => ['#f39922', '#212529'];
        yield 'shipped violet' => ['#986dff', '#212529'];
        yield 'info blue' => ['#5bc0de', '#212529'];
        yield 'grey' => ['#6c757d', '#ffffff'];
        yield 'teal' => ['#20c997', '#212529'];
        yield 'black' => ['#000000', '#ffffff'];
        yield 'white' => ['#ffffff', '#212529'];
        yield 'short notation' => ['#fc0', '#212529'];
        yield 'upper case' => ['#5CB85C', '#212529'];
    }

    #[DataProvider('seededStatusColors')]
    public function testTheTextContrastsWithTheBackground(string $background, string $expected): void
    {
        self::assertSame($expected, (new ContrastColorExtension())->textColor($background));
    }

    public function testAnUnreadableColorKeepsTheWhiteText(): void
    {
        $extension = new ContrastColorExtension();

        self::assertSame('#ffffff', $extension->textColor(null));
        self::assertSame('#ffffff', $extension->textColor(''));
        self::assertSame('#ffffff', $extension->textColor('red'));
    }

    public function testTheFunctionIsAvailableInTemplates(): void
    {
        $twig = new Environment(new ArrayLoader(['badge' => '{{ contrast_text_color(color) }}']));
        $twig->addExtension(new ContrastColorExtension());

        self::assertSame('#212529', $twig->render('badge', ['color' => '#f39922']));
    }
}
