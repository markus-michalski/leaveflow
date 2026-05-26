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

/**
 * Structural problem with the uploaded CSV — missing header, missing
 * required columns, unreadable file. Surfaced as a flash error in the
 * controller; row-level data problems use {@see EmployeeImportRowResult}
 * instead.
 */
final class CsvParseException extends \RuntimeException
{
}
