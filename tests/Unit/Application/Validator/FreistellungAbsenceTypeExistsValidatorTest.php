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

namespace App\Tests\Unit\Application\Validator;

use App\Application\Validator\FreistellungAbsenceTypeExists;
use App\Application\Validator\FreistellungAbsenceTypeExistsValidator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Enum\ExitLeaveHandling;
use App\Domain\Repository\AbsenceTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

#[CoversClass(FreistellungAbsenceTypeExistsValidator::class)]
#[CoversClass(FreistellungAbsenceTypeExists::class)]
final class FreistellungAbsenceTypeExistsValidatorTest extends TestCase
{
    private ExecutionContextInterface&MockObject $context;
    /** @var Stub&AbsenceTypeRepository */
    private Stub $repository;
    private FreistellungAbsenceTypeExistsValidator $validator;
    private Company $company;

    protected function setUp(): void
    {
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->repository = $this->createStub(AbsenceTypeRepository::class);
        $this->validator = new FreistellungAbsenceTypeExistsValidator($this->repository);
        $this->validator->initialize($this->context);
        $this->company = new Company('Acme GmbH');
    }

    #[Test]
    public function addsViolationWhenFreistellungSelectedButNoAbsenceTypeExists(): void
    {
        $this->company->setExitLeaveHandling(ExitLeaveHandling::Freistellung);
        $this->repository->method('findOneByCompanyAndName')->willReturn(null);

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('addViolation')->willReturnSelf();
        $builder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with(FreistellungAbsenceTypeExists::MESSAGE)
            ->willReturn($builder);

        $this->validator->validate($this->company, new FreistellungAbsenceTypeExists());
    }

    #[Test]
    public function noViolationWhenFreistellungSelectedAndAbsenceTypeExists(): void
    {
        $this->company->setExitLeaveHandling(ExitLeaveHandling::Freistellung);
        $absenceType = $this->createStub(AbsenceType::class);
        $this->repository->method('findOneByCompanyAndName')->willReturn($absenceType);

        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($this->company, new FreistellungAbsenceTypeExists());
    }

    #[Test]
    public function noViolationAndNoRepositoryCallWhenHandlingIsNotFreistellung(): void
    {
        $this->company->setExitLeaveHandling(ExitLeaveHandling::PayOut);

        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($this->company, new FreistellungAbsenceTypeExists());
    }

    #[Test]
    public function noViolationWhenValueIsNull(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate(null, new FreistellungAbsenceTypeExists());
    }
}
