<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'security.reset_password.reset.password',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(['message' => 'security.reset_password.error.password_required']),
                    new Length([
                        'min' => 10,
                        'minMessage' => 'security.reset_password.error.password_too_short',
                        'max' => 4096,
                    ]),
                    new NotCompromisedPassword(['message' => 'security.reset_password.error.password_compromised']),
                ],
            ],
            'second_options' => [
                'label' => 'security.reset_password.reset.repeat_password',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'security.reset_password.error.passwords_must_match',
            'mapped' => false,
        ]);
    }
}
