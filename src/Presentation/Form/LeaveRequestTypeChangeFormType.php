<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Phase 9 admin form: reclassify a LeaveRequest's absence type with reason.
 *
 * Reason is mandatory — both the audit trail and the employee notification
 * carry it. The new-type dropdown is scoped to active types of the request's
 * company so cross-company contamination is impossible.
 *
 * @extends AbstractType<array{newType: AbsenceType, reason: string}>
 */
final class LeaveRequestTypeChangeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('newType', EntityType::class, [
                'label' => 'admin.leave_requests.type_change.field.new_type',
                'mapped' => false,
                'class' => AbsenceType::class,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('t')
                    ->andWhere('t.company = :company')
                    ->andWhere('t.active = true')
                    ->setParameter('company', $company)
                    ->orderBy('t.name', 'ASC'),
                'choice_label' => static fn (AbsenceType $t): string => $t->getName(),
                'placeholder' => '—',
                'constraints' => [new NotNull()],
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'admin.leave_requests.type_change.field.reason',
                'help' => 'admin.leave_requests.type_change.field.reason_help',
                'mapped' => false,
                'attr' => ['rows' => 4],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 3, max: 500),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('company')
            ->setAllowedTypes('company', Company::class)
            ->setDefaults(['data_class' => null]);
    }
}
