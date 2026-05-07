<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
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
 * @extends AbstractType<array{hoursGranted: float}>
 */
final class LeaveEntitlementGrantedHoursFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('hoursGranted', NumberType::class, [
            'label' => 'admin.entitlements.field.granted',
            'mapped' => false,
            'scale' => 2,
            'constraints' => [
                new NotBlank(),
                new GreaterThanOrEqual(0),
            ],
            'attr' => ['inputmode' => 'decimal'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
