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

use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Repository\DepartmentRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Admin form for creating / editing a BlackoutPeriod.
 *
 * The "company" option scopes the Department dropdown to the current
 * company so foreign-tenant departments don't leak into the picker.
 *
 * @extends AbstractType<array{
 *     startDate: \DateTimeInterface,
 *     endDate: \DateTimeInterface,
 *     reason: string,
 *     department: Department|null,
 * }>
 */
final class BlackoutPeriodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('startDate', DateType::class, [
                'label' => 'admin.blackout_periods.start_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'attr' => ['placeholder' => 'TT.MM.JJJJ', 'inputmode' => 'numeric'],
                'mapped' => false,
                'constraints' => [new NotNull()],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'admin.blackout_periods.end_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'attr' => ['placeholder' => 'TT.MM.JJJJ', 'inputmode' => 'numeric'],
                'mapped' => false,
                'constraints' => [new NotNull()],
            ])
            ->add('reason', TextType::class, [
                'label' => 'admin.blackout_periods.reason',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('department', EntityType::class, [
                'label' => 'admin.blackout_periods.department',
                'class' => Department::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'admin.blackout_periods.department_company_wide',
                'mapped' => false,
                'query_builder' => static fn (DepartmentRepository $repo): QueryBuilder => $repo
                    ->createQueryBuilder('d')
                    ->where('d.company = :company')
                    ->setParameter('company', $company)
                    ->orderBy('d.name', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
    }
}
