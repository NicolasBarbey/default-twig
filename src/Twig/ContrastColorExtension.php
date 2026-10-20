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

namespace BackOfficeDefaultTwigBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `contrast_text_color(background)` to Twig templates: the text colour
 * (dark or white) that reads best on a status badge whose background colour is
 * chosen by the merchant. It compares the WCAG 2.x contrast ratio of both
 * candidates and keeps the higher one.
 */
final class ContrastColorExtension extends AbstractExtension
{
    private const DARK = '#212529';
    private const LIGHT = '#ffffff';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('contrast_text_color', $this->textColor(...)),
        ];
    }

    /**
     * @param string|null $background a #rgb or #rrggbb colour; anything else gets the white text of the historical badge
     */
    public function textColor(?string $background): string
    {
        $luminance = $this->relativeLuminance($background);

        if (null === $luminance) {
            return self::LIGHT;
        }

        $againstLight = 1.05 / ($luminance + 0.05);
        $againstDark = ($luminance + 0.05) / ((float) $this->relativeLuminance(self::DARK) + 0.05);

        return $againstDark > $againstLight ? self::DARK : self::LIGHT;
    }

    private function relativeLuminance(?string $color): ?float
    {
        if (null === $color || 1 !== preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($color), $matches)) {
            return null;
        }

        $hex = 3 === \strlen($matches[1])
            ? $matches[1][0].$matches[1][0].$matches[1][1].$matches[1][1].$matches[1][2].$matches[1][2]
            : $matches[1];

        $channels = array_map(
            static function (string $channel): float {
                $value = hexdec($channel) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split($hex, 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
