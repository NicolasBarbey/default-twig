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

namespace BackOfficeDefaultTwigBundle\Service\Tag;

/**
 * The colour a tag may be shown with, or nothing.
 *
 * A tag colour reaches a style attribute, and a row written by an import, a
 * module or a hand-run statement never passed the API validator. Every screen
 * that draws a tag owes the same check, so it lives here once instead of being
 * copied into each of them.
 */
final readonly class TagSwatch
{
    /**
     * The same shape the API resource validates a colour against.
     */
    private const COLOR_CODE_SHAPE = '/^#[0-9A-Fa-f]{6}$/';

    /**
     * @return string|null the colour, or null when the tag has none or holds
     *                     something that is not one
     */
    public function color(?string $colorCode): ?string
    {
        $color = (string) $colorCode;

        return preg_match(self::COLOR_CODE_SHAPE, $color) === 1 ? $color : null;
    }
}
