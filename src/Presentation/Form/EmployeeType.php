<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\Weekday;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Admin form to create/edit an Employee.
 *
 * WorkSchedule has two distribution modes (Phase 9):
 * - "auto" — weeklyHours spread evenly across the chosen working days
 *   (the original Phase-2 path, still the default).
 * - "manual" — admin types the hours per weekday directly. Days with
 *   zero hours don't count as a working day. The VO's sum-epsilon
 *   validation surfaces here as a form error if the entered hours
 *   don't add up to weeklyHours.
 *
 * @extends AbstractType<Employee>
 */
final class EmployeeType extends AbstractType
{
    public const string MODE_AUTO = 'auto';
    public const string MODE_MANUAL = 'manual';

    /**
     * Order matches the Weekday enum so iteration in the template stays
     * predictable (Mon..Sun).
     *
     * @var list<array{0: string, 1: Weekday}>
     */
    private const array WEEKDAY_FIELDS = [
        ['hoursMonday', Weekday::Monday],
        ['hoursTuesday', Weekday::Tuesday],
        ['hoursWednesday', Weekday::Wednesday],
        ['hoursThursday', Weekday::Thursday],
        ['hoursFriday', Weekday::Friday],
        ['hoursSaturday', Weekday::Saturday],
        ['hoursSunday', Weekday::Sunday],
    ];

    /**
     * @return list<array{0: string, 1: Weekday}>
     */
    public static function weekdayFieldMap(): array
    {
        return self::WEEKDAY_FIELDS;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];
        $isEdit = (bool) $options['is_edit'];
        /** @var Employee|null $currentEmployee */
        $currentEmployee = $options['current_employee'];

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'admin.employees.full_name',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 150)],
            ])
            ->add('employeeNumber', TextType::class, [
                'label' => 'admin.employees.employee_number',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 50)],
            ])
            ->add('location', EntityType::class, [
                'label' => 'admin.employees.location',
                'mapped' => false,
                'class' => Location::class,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('l')
                    ->andWhere('l.company = :company')
                    ->setParameter('company', $company)
                    ->orderBy('l.name', 'ASC'),
                'choice_label' => 'name',
                'placeholder' => 'admin.employees.location_placeholder',
                'constraints' => [new NotBlank()],
            ])
            ->add('department', EntityType::class, [
                'label' => 'admin.employees.department',
                'help' => 'admin.employees.department_help',
                'mapped' => false,
                'class' => Department::class,
                'required' => false,
                'placeholder' => 'admin.employees.department_placeholder',
                'choice_label' => 'name',
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('d')
                    ->andWhere('d.company = :company')
                    ->setParameter('company', $company)
                    ->orderBy('d.active', 'DESC')
                    ->addOrderBy('d.name', 'ASC'),
            ])
            ->add('weeklyHours', NumberType::class, [
                'label' => 'admin.employees.weekly_hours',
                'mapped' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => '0.5', 'step' => '0.5'],
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ])
            ->add('workingDays', ChoiceType::class, [
                'label' => 'admin.employees.working_days',
                'mapped' => false,
                'choices' => [
                    Weekday::Monday->label() => Weekday::Monday->value,
                    Weekday::Tuesday->label() => Weekday::Tuesday->value,
                    Weekday::Wednesday->label() => Weekday::Wednesday->value,
                    Weekday::Thursday->label() => Weekday::Thursday->value,
                    Weekday::Friday->label() => Weekday::Friday->value,
                    Weekday::Saturday->label() => Weekday::Saturday->value,
                    Weekday::Sunday->label() => Weekday::Sunday->value,
                ],
                'choice_translation_domain' => 'messages',
                'multiple' => true,
                'expanded' => true,
                // Required only in auto mode; the controller does the
                // mode-aware validation since constraints cannot read
                // sibling field state cleanly here.
                'required' => false,
            ])
            ->add('distributionMode', ChoiceType::class, [
                'label' => 'admin.employees.distribution_mode',
                'mapped' => false,
                'choices' => [
                    'admin.employees.distribution_mode_auto' => self::MODE_AUTO,
                    'admin.employees.distribution_mode_manual' => self::MODE_MANUAL,
                ],
                'expanded' => true,
                'multiple' => false,
                // Default kicks in only when the controller hasn't called
                // setData() on this field yet (i.e. /new action). For edit
                // the controller resolves the actual mode from the saved
                // schedule via prefillForm().
                'empty_data' => self::MODE_AUTO,
                'constraints' => [new NotBlank()],
            ])
        ;

        foreach (self::WEEKDAY_FIELDS as [$fieldName, $weekday]) {
            $builder->add($fieldName, NumberType::class, [
                'label' => $weekday->label(),
                'mapped' => false,
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => '0', 'step' => '0.25', 'inputmode' => 'decimal'],
            ]);
        }

        $builder
            ->add('joinedAt', DateType::class, [
                'label' => 'admin.employees.joined_at',
                'mapped' => false,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'attr' => ['placeholder' => 'TT.MM.JJJJ', 'inputmode' => 'numeric'],
                'constraints' => [new NotBlank()],
            ])
            ->add('leftAt', DateType::class, [
                'label' => 'admin.employees.left_at',
                'mapped' => false,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'input' => 'datetime_immutable',
                'attr' => ['placeholder' => 'TT.MM.JJJJ', 'inputmode' => 'numeric'],
                'required' => false,
            ])
            ->add('user', EntityType::class, [
                'label' => 'admin.employees.user',
                'help' => 'admin.employees.user_help',
                'mapped' => false,
                'class' => User::class,
                // Offer active users not yet linked to another employee. In edit
                // mode the currently-linked user is always included — even when
                // deactivated (e.g. after the exit workflow ran) — so the existing
                // link is shown correctly and can be cleared if needed.
                'query_builder' => static function ($repo) use ($company, $currentEmployee) {
                    $linkedUser = $currentEmployee?->getUser();

                    $qb = $repo->createQueryBuilder('u')
                        ->leftJoin('u.employee', 'e')
                        ->andWhere('u.company = :company')
                        ->setParameter('company', $company)
                        ->orderBy('u.email', 'ASC');

                    if (null !== $linkedUser) {
                        $qb->andWhere('u.active = true OR u.id = :linkedUserId')
                            ->setParameter('linkedUserId', $linkedUser->getId());
                    } else {
                        $qb->andWhere('u.active = true');
                    }

                    if (null !== $currentEmployee && null !== $currentEmployee->getId()) {
                        $qb->andWhere('e.id IS NULL OR e.id = :currentEmployeeId')
                            ->setParameter('currentEmployeeId', $currentEmployee->getId());
                    } else {
                        $qb->andWhere('e.id IS NULL');
                    }

                    return $qb;
                },
                'choice_label' => 'email',
                'placeholder' => 'admin.employees.user_placeholder',
                'required' => false,
            ]);

        $builder->setAttribute('is_edit', $isEdit);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'is_edit' => false,
            'current_employee' => null,
        ]);

        $resolver->setRequired(['company']);
        $resolver->setAllowedTypes('company', Company::class);
        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('current_employee', [Employee::class, 'null']);
    }
}
