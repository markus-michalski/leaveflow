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

use App\Application\Validator\ValidLdapFilter;
use App\Application\Validator\ValidLdapFilterValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

#[CoversClass(ValidLdapFilterValidator::class)]
#[CoversClass(ValidLdapFilter::class)]
class ValidLdapFilterTest extends TestCase
{
    private ValidLdapFilterValidator $validator;
    private ExecutionContextInterface&MockObject $context;

    protected function setUp(): void
    {
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new ValidLdapFilterValidator();
        
    }

    #[Test]
    public function acceptsNull(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validateInContext(null, new ValidLdapFilter(), $this->context);
    }

    #[Test]
    #[DataProvider('validFilters')]
    public function acceptsValidFilter(string $filter): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validateInContext($filter, new ValidLdapFilter(), $this->context);
    }

    /** @return array<string, array{string}> */
    public static function validFilters(): array
    {
        return [
            'simple uid filter' => ['(uid={username})'],
            'active directory filter' => ['(sAMAccountName={username})'],
            'complex AND filter' => ['(&(objectClass=user)(sAMAccountName={username}))'],
            'nested OR filter' => ['(|(uid={username})(mail=static@example.com))'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFilters')]
    public function rejectsInvalidFilter(string $filter, string $expectedMessage): void
    {
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('addViolation')->willReturnSelf();
        $builder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($expectedMessage)
            ->willReturn($builder);

        $this->validator->validateInContext($filter, new ValidLdapFilter(), $this->context);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidFilters(): array
    {
        return [
            'missing placeholder' => ['(uid=johndoe)',                        ValidLdapFilter::MSG_NO_PLACEHOLDER],
            'duplicate placeholder' => ['(|(uid={username})(mail={username}))', ValidLdapFilter::MSG_DUPLICATE_PLACEHOLDER],
            'unbalanced open paren' => ['(uid={username}',                      ValidLdapFilter::MSG_UNBALANCED_PARENS],
            'unbalanced close paren' => ['uid={username})',                      ValidLdapFilter::MSG_UNBALANCED_PARENS],
            'extra close paren' => ['(uid={username}))',                    ValidLdapFilter::MSG_UNBALANCED_PARENS],
            'nul byte' => ["(uid=\x00{username})",                ValidLdapFilter::MSG_ILLEGAL_CHARS],
            'newline LF' => ["(uid=\n{username})",                  ValidLdapFilter::MSG_ILLEGAL_CHARS],
            'newline CR' => ["(uid=\r{username})",                  ValidLdapFilter::MSG_ILLEGAL_CHARS],
            'wildcard' => ['(uid=*{username})',                    ValidLdapFilter::MSG_ILLEGAL_CHARS],
        ];
    }
}
