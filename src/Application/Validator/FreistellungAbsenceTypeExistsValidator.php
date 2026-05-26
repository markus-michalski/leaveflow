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

namespace App\Application\Validator;

use App\Domain\Entity\Company;
use App\Domain\Enum\ExitLeaveHandling;
use App\Domain\Repository\AbsenceTypeRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class FreistellungAbsenceTypeExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly AbsenceTypeRepository $repository)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof FreistellungAbsenceTypeExists) {
            throw new UnexpectedTypeException($constraint, FreistellungAbsenceTypeExists::class);
        }

        if (!$value instanceof Company) {
            return;
        }

        if (ExitLeaveHandling::Freistellung !== $value->getExitLeaveHandling()) {
            return;
        }

        if (null === $this->repository->findOneByCompanyAndName($value, 'Freistellung')) {
            $this->context->buildViolation(FreistellungAbsenceTypeExists::MESSAGE)->addViolation();
        }
    }
}
