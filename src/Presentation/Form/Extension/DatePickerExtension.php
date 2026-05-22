<?php

declare(strict_types=1);

namespace App\Presentation\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Automatically wires the Flatpickr Stimulus controller onto every DateType
 * field that uses widget=single_text with html5=false (i.e. text inputs
 * formatted as dd.MM.yyyy). No per-field or per-template changes required.
 */
final class DatePickerExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [DateType::class];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        if ('single_text' !== $options['widget'] || false !== $options['html5']) {
            return;
        }

        $view->vars['attr']['data-controller'] = isset($view->vars['attr']['data-controller'])
            ? $view->vars['attr']['data-controller'].' datepicker'
            : 'datepicker';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // No additional options — relies on the existing widget and html5 options.
    }
}
