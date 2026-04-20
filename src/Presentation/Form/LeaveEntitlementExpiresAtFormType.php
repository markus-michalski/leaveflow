<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array{expiresAt: ?\DateTimeInterface}>
 */
final class LeaveEntitlementExpiresAtFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('expiresAt', DateType::class, [
                'label' => 'admin.entitlements.field.expires_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => false,
                'format' => 'dd.MM.yyyy',
                'attr' => ['placeholder' => 'TT.MM.JJJJ', 'inputmode' => 'numeric'],
                'mapped' => false,
                // Admin may clear the expiry (e.g. illness / parental leave extension).
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
