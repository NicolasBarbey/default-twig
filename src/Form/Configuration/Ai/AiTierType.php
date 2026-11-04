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

namespace BackOfficeDefaultTwigBundle\Form\Configuration\Ai;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiTier;

final class AiTierType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator, private readonly AiLabels $labels)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (AiTier::cases() as $tier) {
            $builder->add($tier->value, ChoiceType::class, [
                'choices' => $options['choices'],
                'required' => false,
                'placeholder' => $this->translator->trans('No model'),
                'label' => $this->labels->tier($tier),
                'help' => $this->labels->tierHelp($tier),
            ]);
            $builder->add($tier->value.'_effort', ChoiceType::class, [
                'choices' => $this->labels->effortChoices(),
                'required' => false,
                'placeholder' => $this->translator->trans('Provider default'),
                'label' => $this->translator->trans('Reasoning effort'),
                'help' => $this->translator->trans('How hard the model thinks before it answers. Only Anthropic models use it; the other providers ignore it. Empty: the provider decides.'),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['choices' => [], 'csrf_token_id' => 'admin.configuration.ai'])
            ->setAllowedTypes('choices', 'array');
    }
}
