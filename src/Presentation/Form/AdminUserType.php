<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<User>
 */
final class AdminUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = (bool) $options['is_edit'];

        if (!$isEdit) {
            $builder->add('email', EmailType::class, [
                'label' => 'admin.users.email',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                ],
            ]);
        }

        $builder->add('role', EnumType::class, [
            'class' => UserRole::class,
            'label' => 'admin.users.role',
            'choice_label' => static fn (UserRole $role): string => $role->label(),
            'choice_value' => static fn (?UserRole $role): string => null === $role ? '' : $role->value,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit' => false,
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
