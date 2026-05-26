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
use App\Domain\Entity\Location;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType as OverrideTypeEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * @extends AbstractType<array{federalState: FederalState, date: \DateTimeInterface, name: string, type: OverrideTypeEnum, location: ?Location}>
 */
final class HolidayOverrideType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('federalState', EnumType::class, [
                'label' => 'admin.holidays.override.federal_state',
                'class' => FederalState::class,
                'choice_label' => static fn (FederalState $state): string => \sprintf('%s — %s', $state->value, ucfirst(strtolower($state->name))),
                'mapped' => false,
                'constraints' => [new NotNull()],
            ])
            ->add('date', DateType::class, [
                'label' => 'admin.holidays.override.date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'attr' => ['placeholder' => 'TT.MM.JJJJ', 'inputmode' => 'numeric'],
                'mapped' => false,
                'constraints' => [new NotNull()],
            ])
            ->add('name', TextType::class, [
                'label' => 'admin.holidays.override.name',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 150)],
            ])
            ->add('type', EnumType::class, [
                'label' => 'admin.holidays.override.type',
                'class' => OverrideTypeEnum::class,
                'choice_label' => static fn (OverrideTypeEnum $t): string => $t->label(),
                'mapped' => false,
                'constraints' => [new NotNull()],
            ])
            ->add('location', EntityType::class, [
                'label' => 'admin.holidays.override.location',
                'help' => 'admin.holidays.override.location_help',
                'class' => Location::class,
                'choice_label' => 'name',
                'placeholder' => 'admin.holidays.override.location_placeholder',
                'required' => false,
                'mapped' => false,
                'query_builder' => static fn ($repo) => $repo->createQueryBuilder('l')
                    ->andWhere('l.company = :company')
                    ->setParameter('company', $company)
                    ->orderBy('l.name', 'ASC'),
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
