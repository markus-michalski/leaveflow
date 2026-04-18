<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Entity\Company;
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
 * Admin form to create/edit an Employee. WorkSchedule is collected as
 * weeklyHours + checkbox list of working days and then auto-distributed.
 *
 * Manual per-day distribution is supported by the VO but UI-deferred to Phase 9.
 *
 * @extends AbstractType<Employee>
 */
final class EmployeeType extends AbstractType
{
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
                'constraints' => [new NotBlank()],
            ])
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
                // Only offer users that are active AND not already linked to another
                // employee. In edit mode, the currently-linked user stays visible
                // (otherwise the form would drop the existing link on re-submit).
                'query_builder' => static function ($repo) use ($company, $currentEmployee) {
                    $qb = $repo->createQueryBuilder('u')
                        ->leftJoin('u.employee', 'e')
                        ->andWhere('u.company = :company')
                        ->andWhere('u.active = true')
                        ->setParameter('company', $company)
                        ->orderBy('u.email', 'ASC');

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
