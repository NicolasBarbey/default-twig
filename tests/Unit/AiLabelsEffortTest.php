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

namespace BackOfficeDefaultTwigBundle\Tests\Unit;

use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiEffort;

/**
 * The screens offer the five levels of the core, weakest first, each under its own word: a level missing from the
 * list could not be chosen, and two levels under one word could not be told apart.
 */
final class AiLabelsEffortTest extends TestCase
{
    public function testEveryLevelOfTheCoreIsOfferedWeakestFirstUnderItsOwnWord(): void
    {
        $choices = (new AiLabels($this->translator()))->effortChoices();

        self::assertSame(array_column(AiEffort::cases(), 'value'), array_values($choices));
        self::assertSame(['Low', 'Medium', 'High', 'Very high', 'Maximum'], array_keys($choices));
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
