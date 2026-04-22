<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Enum\LeaveDayType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Self-service form for an employee to request time off.
 *
 * All fields are `mapped => false` — the controller pulls the raw payload and
 * hands it to LeaveRequestService::create(), which owns the entity
 * construction and balance guards.
 *
 * @extends AbstractType<array{startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, dayType: ?LeaveDayType, absenceType: ?AbsenceType}>
 */
final class LeaveRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('startDate', DateType::class, [
                'label' => 'my.leave_requests.field.start_date',
                'mapped' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'attr' => [
                    'placeholder' => 'TT.MM.JJJJ',
                    'inputmode' => 'numeric',
                    'data-action' => 'change->leave-preview#refresh',
                ],
                'constraints' => [new NotNull()],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'my.leave_requests.field.end_date',
                'mapped' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'attr' => [
                    'placeholder' => 'TT.MM.JJJJ',
                    'inputmode' => 'numeric',
                    'data-action' => 'change->leave-preview#refresh',
                ],
                'constraints' => [new NotNull()],
            ])
            ->add('dayType', EnumType::class, [
                'label' => 'my.leave_requests.field.day_type',
                'mapped' => false,
                'class' => LeaveDayType::class,
                'choice_label' => static fn (LeaveDayType $t): string => 'my.leave_requests.day_type.'.$t->value,
                'data' => LeaveDayType::FullDay,
                'constraints' => [new NotNull()],
                'attr' => [
                    'data-action' => 'change->leave-preview#refresh',
                ],
            ])
            ->add('absenceType', EntityType::class, [
                'label' => 'my.leave_requests.field.absence_type',
                'mapped' => false,
                'class' => AbsenceType::class,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('at')
                    ->andWhere('at.company = :company')
                    ->andWhere('at.active = true')
                    ->setParameter('company', $company)
                    ->orderBy('at.name', 'ASC'),
                'choice_label' => static fn (AbsenceType $a): string => $a->getName(),
                'placeholder' => 'my.leave_requests.field.absence_type_placeholder',
                'constraints' => [new NotNull()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
    }
}
