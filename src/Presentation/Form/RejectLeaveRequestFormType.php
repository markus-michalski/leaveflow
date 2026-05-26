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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Small form for the rejection reason. Kept separate from the approval
 * controller's other actions so the CSRF token is scoped cleanly and the
 * textarea keeps its validation footprint visible to integration tests.
 *
 * @extends AbstractType<array{reason: string}>
 */
final class RejectLeaveRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextareaType::class, [
            'label' => 'manager.approvals.reject.reason',
            'mapped' => false,
            'constraints' => [
                new NotBlank(message: 'manager.approvals.reject.reason_required'),
                new Length(max: 2000),
            ],
            'attr' => [
                'rows' => 3,
                'placeholder' => 'manager.approvals.reject.reason_placeholder',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
