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

namespace BackOfficeDefaultTwigBundle\Service\SearchDictionary;

use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

final readonly class SearchDictionaryLanguage
{
    public function resolve(mixed $requested): Lang
    {
        $id = \is_scalar($requested) ? (int) $requested : 0;

        return ($id > 0 ? LangQuery::create()->findPk($id) : null)
            ?? LangQuery::create()->findOneByByDefault(1)
            ?? LangQuery::create()->findOne()
            ?? throw new \LogicException('The shop has no language.');
    }
}
