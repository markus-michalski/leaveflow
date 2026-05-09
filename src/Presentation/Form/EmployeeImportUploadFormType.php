<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * @extends AbstractType<array{file: ?\Symfony\Component\HttpFoundation\File\UploadedFile}>
 */
final class EmployeeImportUploadFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'admin.employees.import.field.file',
            'mapped' => false,
            'constraints' => [
                new NotNull(),
                new File(
                    maxSize: '2M',
                    extensions: ['csv', 'txt'],
                    extensionsMessage: 'admin.employees.import.error.invalid_extension',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
