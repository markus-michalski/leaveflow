<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'security.reset_password.request.email',
            'attr' => [
                'autocomplete' => 'email',
                'autofocus' => true,
            ],
            'constraints' => [
                new NotBlank(message: 'security.reset_password.error.email_required'),
                new Email(message: 'security.reset_password.error.email_invalid'),
            ],
        ]);
    }
}
