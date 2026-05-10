<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Edit form for adjusting an entitlement's granted-hours value
 * post-creation. Used by admins to correct typos or fold rolled-over
 * overtime into a Carryover bucket.
 *
 * Identity fields (employee/year/type) stay read-only — they form the
 * (employee, year, type) unique key and cascade-touch every booker that
 * already references this entitlement, so editing them in-place would
 * silently break LeaveRequest accounting. Wrong identity → delete + recreate.
 *
 * Phase 9: a non-empty `reason` is required so the audit trail captures
 * the why behind every override.
 *
 * @extends AbstractType<array{hoursGranted: float, reason: string}>
 */
final class LeaveEntitlementGrantedHoursFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('hoursGranted', NumberType::class, [
                'label' => 'admin.entitlements.field.granted',
                'mapped' => false,
                'scale' => 2,
                'constraints' => [
                    new NotBlank(),
                    new GreaterThanOrEqual(0),
                ],
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'admin.entitlements.field.reason',
                'help' => 'admin.entitlements.field.reason_help',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 3, max: 1000),
                ],
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
