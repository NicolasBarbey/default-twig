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

namespace BackOfficeDefaultTwigBundle\Form\Administrator;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdministratorType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isUpdate = $options['include_id'];

        $builder
            ->add('login', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Login'),
            ])
            ->add('firstname', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('First name'),
            ])
            ->add('lastname', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Last name'),
            ])
            ->add('email', EmailType::class, [
                'constraints' => [new NotBlank(), new Email()],
                'label' => $this->translator->trans('Email'),
            ])
            ->add('password', PasswordType::class, [
                'required' => $isUpdate === false,
                'constraints' => $isUpdate ? [] : [new NotBlank(), new Length(min: 4)],
                'label' => $isUpdate
                    ? $this->translator->trans('New password (leave empty to keep current)')
                    : $this->translator->trans('Password'),
            ])
            ->add('profile', ChoiceType::class, [
                'required' => false,
                'placeholder' => $this->translator->trans('Superadministrator'),
                'choices' => $options['profile_choices'],
                'label' => $this->translator->trans('Profile'),
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ]);

        // Only a shop with sale units has anything to choose from.
        if ([] !== $options['sale_unit_choices']) {
            $builder->add('sale_units', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => $options['sale_unit_choices'],
                'label' => $this->translator->trans('Sale units'),
                'help' => $this->translator->trans('The administrator only works on the checked sale units. Check none to give access to all of them.'),
            ]);
        }

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
                'profile_choices' => [],
                'sale_unit_choices' => [],
                'csrf_token_id' => 'admin.administrator',
            ])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('profile_choices', 'array')
            ->setAllowedTypes('sale_unit_choices', 'array');
    }
}
