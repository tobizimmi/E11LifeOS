<?php

namespace App\Support;

/**
 * Guards against CSV formula injection: a cell that starts with "=", "+", "-", "@" or a tab or
 * carriage return is treated as a formula by Excel and other spreadsheet programs once the file
 * is opened, so a bank's counterparty or purpose field could run code on the member's machine.
 */
class Csv
{
    public static function sanitizeCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
