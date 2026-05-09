<?php

declare(strict_types=1);

namespace App\Application\Import;

/**
 * Structural problem with the uploaded CSV — missing header, missing
 * required columns, unreadable file. Surfaced as a flash error in the
 * controller; row-level data problems use {@see EmployeeImportRowResult}
 * instead.
 */
final class CsvParseException extends \RuntimeException
{
}
