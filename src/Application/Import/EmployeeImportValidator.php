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

namespace App\Application\Import;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Enum\Weekday;
use App\Domain\Repository\DepartmentRepository;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LocationRepository;
use App\Domain\Repository\UserRepository;
use App\Domain\ValueObject\WorkSchedule;

/**
 * Validates parsed CSV rows against the current DB state and produces
 * pre-built Employee instances for the rows that pass.
 *
 * Validation is strict: any single bad cell rejects the whole row. Errors
 * are collected (not thrown) so the preview screen can show all problems
 * at once instead of one per fix-and-re-upload cycle.
 *
 * In-file uniqueness checks (no duplicate employeeNumber within the same
 * upload) run before DB lookups so the admin doesn't have to fix three
 * problems sequentially. DB lookups are cached per call to avoid n+1 on
 * imports that all share the same Location.
 *
 * The pre-built Employee instance carries every relation the commit pass
 * needs — Location, optional User, optional Department. Building it twice
 * would mean re-resolving those references; pre-building once keeps the
 * preview ↔ commit semantics aligned.
 */
final readonly class EmployeeImportValidator
{
    public function __construct(
        private LocationRepository $locationRepository,
        private UserRepository $userRepository,
        private DepartmentRepository $departmentRepository,
        private EmployeeRepository $employeeRepository,
    ) {
    }

    /**
     * @param list<EmployeeImportRow> $rows
     *
     * @return list<EmployeeImportRowResult>
     */
    public function validate(Company $company, array $rows): array
    {
        $results = [];

        // Pre-load the company's locations + departments + existing employee
        // numbers so each row check is in-memory rather than a DB hit.
        $locationsByName = $this->indexLocations($company);
        $departmentsByName = $this->indexDepartments($company);
        $existingEmployeeNumbers = $this->indexExistingEmployeeNumbers($company);

        // Track employee numbers seen earlier in the same file — duplicates
        // within the upload are validation errors even if neither row is in
        // the DB yet.
        $seenEmployeeNumbersInFile = [];

        foreach ($rows as $row) {
            $errors = [];
            $location = null;
            $department = null;
            $user = null;
            $schedule = null;
            $joinedAt = null;
            $leftAt = null;

            // Required-string presence
            foreach (['fullName', 'employeeNumber', 'locationName', 'weeklyHours', 'workingDays', 'joinedAt'] as $required) {
                if ('' === trim((string) $row->{$required})) {
                    $errors[] = \sprintf('Pflichtfeld "%s" ist leer.', $required);
                }
            }

            // employeeNumber unique within file + DB
            if ('' !== trim($row->employeeNumber)) {
                $key = strtolower($row->employeeNumber);
                if (isset($seenEmployeeNumbersInFile[$key])) {
                    $errors[] = \sprintf(
                        'Personalnummer "%s" kommt in der Datei mehrfach vor (zuerst in Zeile %d).',
                        $row->employeeNumber,
                        $seenEmployeeNumbersInFile[$key],
                    );
                } else {
                    $seenEmployeeNumbersInFile[$key] = $row->lineNumber;
                }

                if (isset($existingEmployeeNumbers[$key])) {
                    $errors[] = \sprintf('Personalnummer "%s" ist bereits vergeben.', $row->employeeNumber);
                }
            }

            // Location lookup
            if ('' !== trim($row->locationName)) {
                $location = $locationsByName[strtolower($row->locationName)] ?? null;
                if (null === $location) {
                    $errors[] = \sprintf('Standort "%s" existiert nicht.', $row->locationName);
                }
            }

            // Schedule (weeklyHours + workingDays)
            $weeklyHours = $this->parseWeeklyHours($row->weeklyHours, $errors);
            $workingDays = $this->parseWorkingDays($row->workingDays, $errors);
            if (null !== $weeklyHours && [] !== $workingDays) {
                try {
                    $schedule = WorkSchedule::autoDistribute($weeklyHours, $workingDays);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }

            // joinedAt / leftAt
            $joinedAt = $this->parseDate($row->joinedAt, 'joinedAt', $errors);
            if (null !== $row->leftAt) {
                $leftAt = $this->parseDate($row->leftAt, 'leftAt', $errors);
                if (null !== $joinedAt && null !== $leftAt && $leftAt < $joinedAt) {
                    $errors[] = 'leftAt darf nicht vor joinedAt liegen.';
                }
            }

            // Optional User by email
            if (null !== $row->userEmail) {
                $user = $this->userRepository->findOneByEmail($row->userEmail);
                if (null === $user) {
                    $errors[] = \sprintf('Benutzer "%s" existiert nicht.', $row->userEmail);
                } elseif ($user->getCompany() !== $company) {
                    $errors[] = \sprintf('Benutzer "%s" gehört zu einer anderen Firma.', $row->userEmail);
                } elseif (null !== $this->employeeRepository->findOneByUser($user)) {
                    $errors[] = \sprintf('Benutzer "%s" ist bereits mit einem Mitarbeiter verknüpft.', $row->userEmail);
                }
            }

            // Optional Department by name
            if (null !== $row->departmentName) {
                $department = $departmentsByName[strtolower($row->departmentName)] ?? null;
                if (null === $department) {
                    $errors[] = \sprintf('Team "%s" existiert nicht.', $row->departmentName);
                }
            }

            if ([] === $errors && $location instanceof Location && null !== $schedule && null !== $joinedAt) {
                $employee = new Employee(
                    company: $company,
                    fullName: $row->fullName,
                    employeeNumber: $row->employeeNumber,
                    location: $location,
                    workSchedule: $schedule,
                    joinedAt: $joinedAt,
                    user: $user,
                    leftAt: $leftAt,
                );
                if (null !== $department) {
                    $employee->assignToDepartment($department);
                }
                $results[] = new EmployeeImportRowResult($row, employee: $employee);
            } else {
                $results[] = new EmployeeImportRowResult($row, errors: $errors);
            }
        }

        return $results;
    }

    /**
     * @return array<string, Location>
     */
    private function indexLocations(Company $company): array
    {
        $byName = [];
        foreach ($this->locationRepository->findAllByCompany($company) as $loc) {
            $byName[strtolower($loc->getName())] = $loc;
        }

        return $byName;
    }

    /**
     * @return array<string, \App\Domain\Entity\Department>
     */
    private function indexDepartments(Company $company): array
    {
        $byName = [];
        foreach ($this->departmentRepository->findByCompany($company) as $dept) {
            $byName[strtolower($dept->getName())] = $dept;
        }

        return $byName;
    }

    /**
     * @return array<string, true>
     */
    private function indexExistingEmployeeNumbers(Company $company): array
    {
        $byNumber = [];
        foreach ($this->employeeRepository->findAllByCompany($company) as $employee) {
            $byNumber[strtolower($employee->getEmployeeNumber())] = true;
        }

        return $byNumber;
    }

    /**
     * @param list<string> $errors
     */
    private function parseWeeklyHours(string $raw, array &$errors): ?float
    {
        if ('' === trim($raw)) {
            return null;
        }
        // Accept comma-decimal too (German Excel default).
        $normalized = str_replace(',', '.', trim($raw));
        if (1 !== preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            $errors[] = \sprintf('weeklyHours "%s" ist keine Zahl.', $raw);

            return null;
        }
        $value = (float) $normalized;
        if ($value <= 0) {
            $errors[] = 'weeklyHours muss > 0 sein.';

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<Weekday>
     */
    private function parseWorkingDays(string $raw, array &$errors): array
    {
        if ('' === trim($raw)) {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        $days = [];
        foreach ($parts as $part) {
            if (1 !== preg_match('/^[1-7]$/', $part)) {
                $errors[] = \sprintf('workingDays "%s" muss eine Komma-Liste aus 1..7 sein (z.B. "1,2,3,4,5").', $raw);

                return [];
            }
            $weekday = Weekday::tryFrom((int) $part);
            if (null === $weekday) {
                $errors[] = \sprintf('workingDays "%s" enthält ungültigen Tag %s.', $raw, $part);

                return [];
            }
            $days[] = $weekday;
        }

        return $days;
    }

    /**
     * @param list<string> $errors
     */
    private function parseDate(string $raw, string $fieldName, array &$errors): ?\DateTimeImmutable
    {
        if ('' === trim($raw)) {
            return null;
        }
        // Accept ISO-8601 (yyyy-mm-dd) AND German dd.mm.yyyy.
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($parsed instanceof \DateTimeImmutable && $parsed->format($format) === $raw) {
                return $parsed->setTime(0, 0);
            }
        }
        $errors[] = \sprintf('%s "%s" ist kein gültiges Datum (yyyy-mm-dd oder dd.mm.yyyy).', $fieldName, $raw);

        return null;
    }
}
