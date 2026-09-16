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

namespace BackOfficeDefaultTwigBundle\Form\Lang;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LangUrlType extends AbstractType
{
    public const FIELD_PREFIX = 'url_';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // No NotBlank here: the domains have to be savable one at a time, and languages that are
        // not served in front office never need one. Completeness is enforced when the setting is
        // activated, in LangController::switchDomainPerLang().
        foreach ($options['languages'] as $lang) {
            $builder->add(self::FIELD_PREFIX.$lang['id'], UrlType::class, [
                'data' => $lang['url'] ?? '',
                'default_protocol' => 'https',
                'required' => false,
                'label' => $lang['title'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('languages')
            ->setAllowedTypes('languages', 'array')
            ->setDefaults([
                'csrf_token_id' => 'admin.lang.url',
            ]);
    }
}
