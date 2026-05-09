<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Import;

use App\Application\Import\CsvParseException;
use App\Application\Import\EmployeeCsvParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmployeeCsvParser::class)]
final class EmployeeCsvParserTest extends TestCase
{
    private EmployeeCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EmployeeCsvParser();
    }

    #[Test]
    public function parsesValidCsvIntoRows(): void
    {
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            ."Erika Mustermann,EMP-1001,HQ,40,\"1,2,3,4,5\",2025-01-15\n";

        $rows = $this->parser->parse($csv);

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]->lineNumber);
        self::assertSame('Erika Mustermann', $rows[0]->fullName);
        self::assertSame('EMP-1001', $rows[0]->employeeNumber);
        self::assertSame('1,2,3,4,5', $rows[0]->workingDays);
        self::assertNull($rows[0]->leftAt);
        self::assertNull($rows[0]->userEmail);
    }

    #[Test]
    public function trimsCellWhitespace(): void
    {
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            .'"  Erika  ","  EMP-1  ","  HQ  ",40,"1,2,3,4,5",2025-01-15'."\n";

        $rows = $this->parser->parse($csv);

        self::assertSame('Erika', $rows[0]->fullName);
        self::assertSame('EMP-1', $rows[0]->employeeNumber);
        self::assertSame('HQ', $rows[0]->locationName);
    }

    #[Test]
    public function readsOptionalColumnsAndNullsEmptyOnes(): void
    {
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt,leftAt,userEmail,departmentName\n"
            .'A,1,HQ,40,"1,2,3,4,5",2025-01-15,2026-12-31,a@example.test,Sales'."\n"
            .'B,2,HQ,40,"1,2,3,4,5",2025-01-15,,,'."\n";

        $rows = $this->parser->parse($csv);

        self::assertSame('2026-12-31', $rows[0]->leftAt);
        self::assertSame('a@example.test', $rows[0]->userEmail);
        self::assertSame('Sales', $rows[0]->departmentName);
        self::assertNull($rows[1]->leftAt);
        self::assertNull($rows[1]->userEmail);
        self::assertNull($rows[1]->departmentName);
    }

    #[Test]
    public function skipsTrailingEmptyRows(): void
    {
        $csv = "fullName,employeeNumber,locationName,weeklyHours,workingDays,joinedAt\n"
            .'A,1,HQ,40,"1,2,3,4,5",2025-01-15'."\n"
            ."\n";

        $rows = $this->parser->parse($csv);

        self::assertCount(1, $rows);
    }

    #[Test]
    public function rejectsCsvWithoutHeader(): void
    {
        $this->expectException(CsvParseException::class);
        $this->expectExceptionMessage('empty');

        $this->parser->parse('');
    }

    #[Test]
    public function rejectsCsvMissingRequiredColumns(): void
    {
        $this->expectException(CsvParseException::class);
        $this->expectExceptionMessage('missing required columns');

        $this->parser->parse('fullName,employeeNumber'."\n".'A,1'."\n");
    }
}
