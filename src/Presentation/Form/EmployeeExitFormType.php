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

use App\Domain\Enum\ExitLeaveHandling;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, mixed>> */
final class EmployeeExitFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('exitDate', DateType::class, [
                'label' => 'admin.employees.exit.exit_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'data' => $options['default_exit_date'],
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('exitLeaveHandling', EnumType::class, [
                'class' => ExitLeaveHandling::class,
                'label' => 'admin.employees.exit.handling',
                'choice_label' => static fn (ExitLeaveHandling $e) => $e->translationKey(),
                'choice_translation_domain' => 'messages',
                'data' => $options['company_default'],
                'constraints' => [new Assert\NotNull()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'company_default' => null,
            'default_exit_date' => null,
            'csrf_protection' => true,
        ]);
        $resolver->setAllowedTypes('company_default', ['null', ExitLeaveHandling::class]);
        $resolver->setAllowedTypes('default_exit_date', ['null', \DateTimeImmutable::class]);
    }
}
