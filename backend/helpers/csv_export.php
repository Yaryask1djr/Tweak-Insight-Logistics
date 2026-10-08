<?php

/** Spreadsheet-safe CSV encoding for user-controlled fields. */
final class CsvExport
{
    public static function cell(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        // A quoted CSV field can still be interpreted as a spreadsheet formula.
        if (preg_match('/^[\x00-\x20]*[=+@-]/', $value) || preg_match('/^[\t\r\n]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function write($stream, array $row): void
    {
        if (fputcsv($stream, array_map([self::class, 'cell'], array_values($row)), ',', '"', '') === false) {
            throw new RuntimeException('Unable to write CSV export.');
        }
    }
}
