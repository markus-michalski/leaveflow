<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{name: string, expiresAt: ?\DateTimeImmutable}>
 */
final class ApiTokenFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.api_tokens.field.name',
                'constraints' => [
                    new Assert\NotBlank(message: 'admin.api_tokens.error.name_required'),
                    new Assert\Length(max: 100),
                ],
                'attr' => ['placeholder' => 'admin.api_tokens.field.name_placeholder'],
            ])
            ->add('expiresAt', DateType::class, [
                'label' => 'admin.api_tokens.field.expires_at',
                'required' => false,
                'widget' => 'single_text',
                'constraints' => [
                    new Assert\GreaterThan('today', message: 'admin.api_tokens.error.expires_at_future'),
                ],
            ]);
    }
}
