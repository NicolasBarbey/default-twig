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

namespace BackOfficeDefaultTwigBundle\Form\Configuration;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TagType extends AbstractType
{
    /**
     * The same shape the API resource validates a colour against. Declared once
     * here and reused, rather than a second rule that could drift from it.
     */
    public const COLOR_CODE_SHAPE = '/^#[0-9A-Fa-f]{6}$/';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                // trim as a normalizer, so a label of nothing but spaces is refused
                // here as well as at the API: the model reduces it to an empty
                // string on save, which would leave a tag nobody can name.
                'constraints' => [new NotBlank(normalizer: 'trim'), new Length(max: 100)],
                'label' => $this->translator->trans('Label'),
            ])
            ->add('colorCode', ColorType::class, [
                'required' => false,
                'constraints' => [new Regex(
                    pattern: self::COLOR_CODE_SHAPE,
                    message: $this->translator->trans('The colour must be a hexadecimal code, for instance #1A2B3C.'),
                )],
                'label' => $this->translator->trans('Colour'),
            ])
            // A native colour input always posts a colour — it has no empty state,
            // and defaults to black. Without this checkbox a tag with no colour
            // would silently turn black on its first save, and a colour could
            // never be taken back off.
            ->add('noColor', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('No colour'),
            ])
        ;

        // Absent from the creation form: there is no tag to point at yet, and a
        // required identifier would refuse every submission.
        if ($options['include_id']) {
            $builder->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'csrf_token_id' => 'admin.tag',
                'include_id' => true,
            ])
            ->setAllowedTypes('include_id', 'bool');
    }
}
