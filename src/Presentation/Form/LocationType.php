<?php

declare(strict_types=1);

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
                'help' => 'admin.locations.federal_state_help',
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
