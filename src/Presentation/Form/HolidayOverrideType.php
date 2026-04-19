<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType as OverrideTypeEnum;
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
 * @extends AbstractType<array{federalState: FederalState, date: \DateTimeInterface, name: string, type: OverrideTypeEnum}>
 */
final class HolidayOverrideType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
