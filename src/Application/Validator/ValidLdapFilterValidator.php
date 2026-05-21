<?php

declare(strict_types=1);

namespace App\Application\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidLdapFilterValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidLdapFilter) {
            throw new UnexpectedTypeException($constraint, ValidLdapFilter::class);
        }

        if (null === $value) {
            return;
        }

        $filter = (string) $value;

        if (str_contains($filter, "\x00") || str_contains($filter, "\n") || str_contains($filter, "\r") || str_contains($filter, '*')) {
            $this->context->buildViolation(ValidLdapFilter::MSG_ILLEGAL_CHARS)->addViolation();

            return;
        }

        $count = substr_count($filter, '{username}');
        if (0 === $count) {
            $this->context->buildViolation(ValidLdapFilter::MSG_NO_PLACEHOLDER)->addViolation();

            return;
        }
        if ($count > 1) {
            $this->context->buildViolation(ValidLdapFilter::MSG_DUPLICATE_PLACEHOLDER)->addViolation();

            return;
        }

        $depth = 0;
        foreach (str_split($filter) as $char) {
            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                --$depth;
                if ($depth < 0) {
                    $this->context->buildViolation(ValidLdapFilter::MSG_UNBALANCED_PARENS)->addViolation();

                    return;
                }
            }
        }

        if (0 !== $depth) {
            $this->context->buildViolation(ValidLdapFilter::MSG_UNBALANCED_PARENS)->addViolation();
        }
    }
}
