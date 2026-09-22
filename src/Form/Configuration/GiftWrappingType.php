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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GiftWrappingType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isUpdate = $options['include_id'];

        $builder
            ->add('code', TextType::class, [
                // The code names the wrapping on the order lines it produced and in any
                // export grouping them: free to pick once, frozen for good afterwards.
                'required' => !$isUpdate,
                'disabled' => $isUpdate,
                'constraints' => $isUpdate ? [] : [new NotBlank()],
                'label' => $this->translator->trans('Code'),
            ])
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Title'),
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Description'),
            ])
            ->add('price', NumberType::class, [
                // Zero is a wrapping the shop offers, not a missing price, so the field is
                // required and the floor is zero rather than a cent.
                'scale' => 6,
                'html5' => true,
                'constraints' => [new NotNull(), new GreaterThanOrEqual(0)],
                'label' => $this->translator->trans('Price (tax excluded)'),
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            ->add('tax_rule_id', ChoiceType::class, [
                'constraints' => [new NotNull(), new GreaterThan(0)],
                'label' => $this->translator->trans('Tax rule'),
                'choices' => $options['tax_rule_choices'],
                'placeholder' => $this->translator->trans('Choose a tax rule'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('This gift wrapping is offered at checkout'),
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ]);

        if ($isUpdate) {
            $builder->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'include_id' => false,
                'tax_rule_choices' => [],
                'csrf_token_id' => 'admin.gift-wrapping',
            ])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('tax_rule_choices', 'array');
    }
}
