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
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\LangQuery;

final class SearchSynonymType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $languages = [];
        foreach (LangQuery::create()->orderByPosition()->find() as $lang) {
            $languages[(string) $lang->getTitle()] = (int) $lang->getId();
        }

        $builder
            ->add('lang_id', ChoiceType::class, [
                'choices' => $languages,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Language'),
            ])
            ->add('term', TextType::class, [
                'constraints' => [new NotBlank(normalizer: 'trim'), new Length(max: 255)],
                'label' => $this->translator->trans('Term'),
            ])
            ->add('synonyms', TextareaType::class, [
                'constraints' => [new NotBlank(normalizer: 'trim')],
                'label' => $this->translator->trans('Equivalent terms'),
                'help' => $this->translator->trans('One term per line.'),
                'attr' => ['rows' => 5],
            ])
            ->add('bidirectional', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Both directions'),
                'help' => $this->translator->trans('Searching for any of the equivalent terms also finds the term.'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Active'),
            ]);

        if ($options['include_id']) {
            $builder->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['include_id' => false, 'csrf_token_id' => 'admin.search_synonym'])
            ->setAllowedTypes('include_id', 'bool');
    }
}
