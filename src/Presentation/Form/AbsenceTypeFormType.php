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

use App\Domain\Enum\LeaveEntitlementType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array{name: string, deductsFromLeave: bool, requiresApproval: bool, color: string, active: bool, requiredBucket: ?LeaveEntitlementType, illnessTracking: bool}>
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
            ->add('active', CheckboxType::class, [
                'label' => 'admin.absence_types.field.active',
                'mapped' => false,
                'required' => false,
            ])
            ->add('requiredBucket', EnumType::class, [
                'label' => 'admin.absence_types.field.requiredBucket',
                'help' => 'admin.absence_types.field.requiredBucket_help',
                'mapped' => false,
                'class' => LeaveEntitlementType::class,
                'choice_label' => static fn (LeaveEntitlementType $t): string => 'admin.absence_types.bucket.'.$t->value,
                'placeholder' => 'admin.absence_types.bucket.unified',
                'required' => false,
            ])
            ->add('illnessTracking', CheckboxType::class, [
                'label' => 'admin.absence_types.field.illnessTracking',
                'help' => 'admin.absence_types.field.illnessTracking_help',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
