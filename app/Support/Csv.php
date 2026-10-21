<?php

namespace App\Support;

/**
 * CSV/formula injection guard for downloads built from what the public
 * typed: a cell starting with = + - @ or a tab/CR is prefixed with ' so a
 * spreadsheet shows it as text instead of running it as a formula.
 */
class Csv
{
    public static function safe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
