<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Enum\LeaveEntitlementType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin form to create a new LeaveEntitlement for an employee.
 *
 * Scope is intentionally minimal: employee + year + type + hoursGranted + optional expiresAt.
 * hoursUsed starts at 0 and is never editable from the UI — consumption happens
 * via EntitlementConsumer from approved LeaveRequests (Phase 5).
 *
 * @extends AbstractType<array{employee: Employee, year: int, type: LeaveEntitlementType, hoursGranted: float, expiresAt: ?\DateTimeImmutable}>
 */
final class LeaveEntitlementFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('employee', EntityType::class, [
                'label' => 'admin.entitlements.field.employee',
                'mapped' => false,
                'class' => Employee::class,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('e')
                    ->andWhere('e.company = :company')
                    ->andWhere('e.leftAt IS NULL')
                    ->setParameter('company', $company)
                    ->orderBy('e.fullName', 'ASC'),
                'choice_label' => static fn (Employee $e): string => \sprintf('%s (%s)', $e->getFullName(), $e->getEmployeeNumber()),
                'placeholder' => 'admin.entitlements.field.employee_placeholder',
                'constraints' => [new NotNull()],
            ])
            ->add('year', IntegerType::class, [
                'label' => 'admin.entitlements.field.year',
                'mapped' => false,
                'constraints' => [
                    new NotNull(),
                    new Range(min: 1970, max: 2200),
                ],
                'attr' => ['inputmode' => 'numeric'],
            ])
            ->add('type', EnumType::class, [
                'label' => 'admin.entitlements.field.type',
                'mapped' => false,
                'class' => LeaveEntitlementType::class,
                'choice_label' => static fn (LeaveEntitlementType $t): string => 'admin.entitlements.type.'.$t->value,
                'constraints' => [new NotNull()],
            ])
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
            ->add('expiresAt', DateType::class, [
                'label' => 'admin.entitlements.field.expires_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'attr' => ['placeholder' => 'TT.MM.JJJJ', 'inputmode' => 'numeric'],
                'mapped' => false,
                'required' => false,
            ])
            ->addEventListener(
                FormEvents::POST_SUBMIT,
                function (FormEvent $event): void {
                    $form = $event->getForm();
                    $expiresAt = $form->get('expiresAt')->getData();
                    $year = $form->get('year')->getData();
                    if (!$expiresAt instanceof \DateTimeInterface || !\is_int($year)) {
                        return;
                    }
                    $yearStart = new \DateTimeImmutable(\sprintf('%d-01-01', $year));
                    if ($expiresAt < $yearStart) {
                        $form->get('expiresAt')->addError(new FormError(
                            $this->translator->trans('admin.entitlements.error.expires_before_year', [
                                '%year%' => (string) $year,
                            ])
                        ));
                    }
                },
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('company')
            ->setAllowedTypes('company', Company::class)
            ->setDefaults(['data_class' => null]);
    }
}
