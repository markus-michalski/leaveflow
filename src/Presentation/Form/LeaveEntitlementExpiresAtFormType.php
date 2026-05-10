<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Edit-only form for the carryover expiry date. The owning entitlement's
 * year is passed as `entitlement_year` so the cross-field rule can fire
 * inline (issue #23): a carryover expiry that pre-dates the entitlement
 * year would gate leave before it even applies.
 *
 * Phase 9: a non-empty `reason` is required so the audit trail captures
 * the why behind every override.
 *
 * @extends AbstractType<array{expiresAt: ?\DateTimeInterface, reason: string}>
 */
final class LeaveEntitlementExpiresAtFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var int $entitlementYear */
        $entitlementYear = $options['entitlement_year'];

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
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'admin.entitlements.field.reason',
                'help' => 'admin.entitlements.field.reason_help',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 3, max: 1000),
                ],
                'attr' => ['rows' => 3],
            ])
            ->addEventListener(
                FormEvents::POST_SUBMIT,
                function (FormEvent $event) use ($entitlementYear): void {
                    $expiresAt = $event->getForm()->get('expiresAt')->getData();
                    if (!$expiresAt instanceof \DateTimeInterface) {
                        return;
                    }
                    // BUrlG §7 Abs. 3 floor: admin may extend, not shorten.
                    $burlgFloor = new \DateTimeImmutable(\sprintf('%d-03-31', $entitlementYear));
                    if ($expiresAt < $burlgFloor) {
                        $event->getForm()->get('expiresAt')->addError(new FormError(
                            $this->translator->trans('admin.entitlements.error.expires_before_burlg_floor', [
                                '%year%' => (string) $entitlementYear,
                            ])
                        ));
                    }
                },
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('entitlement_year')
            ->setAllowedTypes('entitlement_year', 'int')
            ->setDefaults(['data_class' => null]);
    }
}
