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
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Search\Dictionary\SearchRedirectTargetType;
use Thelia\Model\LangQuery;

final class SearchTermRedirectType extends AbstractType
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

        $types = [
            $this->translator->trans('Product') => SearchRedirectTargetType::PRODUCT,
            $this->translator->trans('Category') => SearchRedirectTargetType::CATEGORY,
            $this->translator->trans('Content') => SearchRedirectTargetType::CONTENT,
            $this->translator->trans('Folder') => SearchRedirectTargetType::FOLDER,
            $this->translator->trans('Brand') => SearchRedirectTargetType::BRAND,
            $this->translator->trans('Shop path') => SearchRedirectTargetType::URL,
        ];

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
            ->add('target_type', ChoiceType::class, [
                'choices' => $types,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Target type'),
            ])
            ->add('target_id', IntegerType::class, [
                'required' => false,
                'constraints' => [new Positive()],
                'label' => $this->translator->trans('Identifier of the target'),
                'help' => $this->translator->trans('Required unless the target is a shop path.'),
            ])
            ->add('url', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 2048)],
                'label' => $this->translator->trans('Shop path'),
                'help' => $this->translator->trans('A path of the shop, starting with /'),
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
            ->setDefaults(['include_id' => false, 'csrf_token_id' => 'admin.search_redirect'])
            ->setAllowedTypes('include_id', 'bool');
    }
}
