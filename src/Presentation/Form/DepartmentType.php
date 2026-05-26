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
use App\Domain\Entity\Employee;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<Department>
 */
final class DepartmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.departments.name',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 150)],
            ])
            ->add('lead', EntityType::class, [
                'label' => 'admin.departments.lead',
                'class' => Employee::class,
                'mapped' => false,
                'required' => false,
                'placeholder' => 'admin.departments.no_lead',
                'choice_label' => static fn (Employee $e): string => $e->getFullName().' ('.$e->getEmployeeNumber().')',
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('e')
                    ->where('e.company = :company')
                    ->andWhere('e.leftAt IS NULL')
                    ->setParameter('company', $company)
                    ->orderBy('e.fullName', 'ASC'),
            ])
            ->add('deputy', EntityType::class, [
                'label' => 'admin.departments.deputy',
                'class' => Employee::class,
                'mapped' => false,
                'required' => false,
                'placeholder' => 'admin.departments.no_deputy',
                'choice_label' => static fn (Employee $e): string => $e->getFullName().' ('.$e->getEmployeeNumber().')',
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('e')
                    ->where('e.company = :company')
                    ->andWhere('e.leftAt IS NULL')
                    ->setParameter('company', $company)
                    ->orderBy('e.fullName', 'ASC'),
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'admin.departments.active',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => null])
            ->setRequired('company')
            ->setAllowedTypes('company', Company::class);
    }
}
