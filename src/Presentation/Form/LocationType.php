<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace App\Presentation\Form;

use App\Domain\Entity\Location;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<Location>
 */
final class LocationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.locations.name',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 150)],
            ])
            ->add('country', TextType::class, [
                'label' => 'admin.locations.country',
                'help' => 'admin.locations.country_help',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(min: 2, max: 2)],
            ])
            ->add('federalState', TextType::class, [
                'label' => 'admin.locations.federal_state',
                // Help text rendered manually in the Twig template (see form.html.twig)
                // so we can embed a link to the ISO 3166-2 reference without fighting
                // Symfony's help_html escaping.
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 10)],
            ])
            ->add('city', TextType::class, [
                'label' => 'admin.locations.city',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 150)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
