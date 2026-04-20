<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array{name: string, deductsFromLeave: bool, requiresApproval: bool, color: string, icon: string, active: bool}>
 */
final class AbsenceTypeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.absence_types.field.name',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('deductsFromLeave', CheckboxType::class, [
                'label' => 'admin.absence_types.field.deductsFromLeave',
                'mapped' => false,
                'required' => false,
            ])
            ->add('requiresApproval', CheckboxType::class, [
                'label' => 'admin.absence_types.field.requiresApproval',
                'mapped' => false,
                'required' => false,
            ])
            ->add('color', ColorType::class, [
                'label' => 'admin.absence_types.field.color',
                'mapped' => false,
                'constraints' => [new NotBlank()],
                // Hex color pattern (#RGB or #RRGGBB) — matches AbsenceType::HEX_COLOR_PATTERN
                'attr' => ['pattern' => '^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$'],
            ])
            ->add('icon', TextType::class, [
                'label' => 'admin.absence_types.field.icon',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 50)],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'admin.absence_types.field.active',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
