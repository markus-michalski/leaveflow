<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Entity\Company;
use App\Domain\Enum\ExitLeaveHandling;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Profile form for the single-tenant Company. Wired against
 * setter methods (`name` via rename, address/taxId/etc. as plain
 * setters) so validation lives here at the boundary while the
 * Company entity stays canonical.
 *
 * The logo field is unmapped — file uploads need handling in the
 * controller (move to public/uploads/, persist relative path). The
 * 2FA-requirement toggle stays in its own form on the same page;
 * combining them would mix file-upload state with a boolean toggle
 * and complicate validation.
 *
 * @extends AbstractType<Company>
 */
final class CompanyProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.company_settings.profile.name',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 200),
                ],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'admin.company_settings.profile.address',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('taxId', TextType::class, [
                'label' => 'admin.company_settings.profile.tax_id',
                'required' => false,
                'constraints' => [new Assert\Length(max: 50)],
            ])
            ->add('commercialRegister', TextType::class, [
                'label' => 'admin.company_settings.profile.commercial_register',
                'required' => false,
                'constraints' => [new Assert\Length(max: 100)],
            ])
            ->add('primaryColor', ColorType::class, [
                'label' => 'admin.company_settings.profile.primary_color',
                'required' => false,
                'empty_data' => null,
            ])
            ->add('retentionPeriodMonths', IntegerType::class, [
                'label' => 'admin.company_settings.profile.retention_months',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 1, max: 360),
                ],
            ])
            ->add('approvalEscalationDays', IntegerType::class, [
                'label' => 'admin.company_settings.profile.escalation_days',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 1, max: 90),
                ],
            ])
            ->add('exitLeaveHandling', EnumType::class, [
                'class' => ExitLeaveHandling::class,
                'label' => 'admin.company_settings.profile.exit_leave_handling',
                'choice_label' => static fn (ExitLeaveHandling $e) => $e->translationKey(),
                'choice_translation_domain' => 'messages',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('logo', FileType::class, [
                'label' => 'admin.company_settings.profile.logo',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '1M',
                        mimeTypes: ['image/png', 'image/jpeg', 'image/svg+xml'],
                        mimeTypesMessage: 'admin.company_settings.profile.logo_mime_error',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
        ]);
    }
}
