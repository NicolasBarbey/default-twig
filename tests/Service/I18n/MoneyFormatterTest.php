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

namespace BackOfficeDefaultTwigBundle\Tests\Service\I18n;

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'dollar, english admin' => ['en_US', 'USD', '$1,234.50'];
        yield 'dollar, french admin' => ['fr_FR', 'USD', "1\u{202F}234,50\u{a0}\$US"];
        yield 'euro, english admin' => ['en_US', 'EUR', '€1,234.50'];
        yield 'euro, french admin' => ['fr_FR', 'EUR', "1\u{202F}234,50\u{a0}€"];
    }

    #[DataProvider('amounts')]
    public function testTheAmountCarriesItsOwnCurrencyAndTheLocaleOfTheAdmin(string $locale, string $code, string $expected): void
    {
        self::assertSame($expected, (new MoneyFormatter())->format(1234.5, $locale, $code));
    }
}
