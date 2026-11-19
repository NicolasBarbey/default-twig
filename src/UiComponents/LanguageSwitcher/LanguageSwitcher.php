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

namespace BackOfficeDefaultTwigBundle\UiComponents\LanguageSwitcher;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoLanguageSwitcher', template: '@BackOfficeDefaultTwig/components/LanguageSwitcher/LanguageSwitcher.html.twig')]
final class LanguageSwitcher
{
    public int $currentLanguageId;

    public string $editParam = 'edit_language_id';

    public string $label = 'Edit in';

    /** @var list<string> */
    public array $resetParams = [];
}
