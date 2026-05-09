<?php

declare(strict_types=1);

namespace App\Application\Import;

/**
 * Pure CSV → row-DTO transformation. No validation, no DB lookups, no
 * exceptions on bad data — that's the validator's job.
 *
 * Format expectations (matched against the downloadable template):
 * - Header row required, first non-empty line of the file
 * - Comma separator, double-quote enclosures, UTF-8 encoded
 * - Required columns (any order): fullName, employeeNumber, locationName,
 *   weeklyHours, workingDays, joinedAt
 * - Optional columns: leftAt, userEmail, departmentName
 * - Unknown columns are silently dropped
 *
 * Trims whitespace per cell. Empty optional cells become null (not '').
 *
 * Throws {@see CsvParseException} only for structural problems (no header,
 * inconsistent column count) — unparseable individual cell values flow
 * through and become validator errors so admins see "row 7 weeklyHours
 * must be a number" rather than a generic parse fail.
 */
final readonly class EmployeeCsvParser
{
    private const array REQUIRED_COLUMNS = [
        'fullName',
        'employeeNumber',
        'locationName',
        'weeklyHours',
        'workingDays',
        'joinedAt',
    ];

    /**
     * @return list<EmployeeImportRow>
     */
    public function parse(string $csvContent): array
    {
        $stream = fopen('php://memory', 'r+');
        if (false === $stream) {
            throw new CsvParseException('Failed to allocate memory stream for CSV parsing.');
        }
        fwrite($stream, $csvContent);
        rewind($stream);

        $header = fgetcsv($stream, escape: '');
        if (false === $header) {
            fclose($stream);
            throw new CsvParseException('CSV is empty or unreadable.');
        }

        $header = array_map(static fn (?string $h): string => trim((string) $h), $header);
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ([] !== $missing) {
            fclose($stream);
            throw new CsvParseException(\sprintf('CSV is missing required columns: %s', implode(', ', $missing)));
        }

        $rows = [];
        $lineNumber = 1; // header
        while (false !== ($cells = fgetcsv($stream, escape: ''))) {
            ++$lineNumber;
            // Skip empty trailing lines without erroring out — a CSV ending
            // with a newline parses as an empty row.
            if ([null] === $cells || $this->isEmpty($cells)) {
                continue;
            }

            $assoc = $this->associate($header, $cells);
            $rows[] = new EmployeeImportRow(
                lineNumber: $lineNumber,
                fullName: $this->cell($assoc, 'fullName'),
                employeeNumber: $this->cell($assoc, 'employeeNumber'),
                locationName: $this->cell($assoc, 'locationName'),
                weeklyHours: $this->cell($assoc, 'weeklyHours'),
                workingDays: $this->cell($assoc, 'workingDays'),
                joinedAt: $this->cell($assoc, 'joinedAt'),
                leftAt: $this->optionalCell($assoc, 'leftAt'),
                userEmail: $this->optionalCell($assoc, 'userEmail'),
                departmentName: $this->optionalCell($assoc, 'departmentName'),
            );
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @param list<string|null> $cells
     */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (null !== $cell && '' !== trim((string) $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>      $header
     * @param list<string|null> $cells
     *
     * @return array<string, string>
     */
    private function associate(array $header, array $cells): array
    {
        $assoc = [];
        foreach ($header as $i => $name) {
            $assoc[$name] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
        }

        return $assoc;
    }

    /**
     * @param array<string, string> $row
     */
    private function cell(array $row, string $key): string
    {
        return $row[$key] ?? '';
    }

    /**
     * @param array<string, string> $row
     */
    private function optionalCell(array $row, string $key): ?string
    {
        $value = $row[$key] ?? '';

        return '' === $value ? null : $value;
    }
}
