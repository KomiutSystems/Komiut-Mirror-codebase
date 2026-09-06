<?php

declare(strict_types=1);

namespace App\Services\Sql;

use Illuminate\Support\Facades\DB;

/**
 * Driver-portable raw-SQL fragments for grouping/ordering by calendar parts of
 * a date column. MySQL's DAYNAME()/DAYOFMONTH()/YEAR()/MONTH() have no direct
 * Postgres equivalent (EXTRACT/TO_CHAR instead) and no sqlite equivalent at all
 * (strftime instead) — this keeps that branching in one place instead of
 * repeated ad-hoc DB::raw() calls per controller.
 */
final class DatePartSql
{
    public static function dayName(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "trim(to_char({$column}, 'FMDay'))",
            'sqlite' => "case cast(strftime('%w', {$column}) as integer) ".
                "when 0 then 'Sunday' when 1 then 'Monday' when 2 then 'Tuesday' ".
                "when 3 then 'Wednesday' when 4 then 'Thursday' when 5 then 'Friday' ".
                "else 'Saturday' end",
            default => "DAYNAME({$column})",
        };
    }

    public static function dayOfMonth(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "extract(day from {$column})",
            'sqlite' => "cast(strftime('%d', {$column}) as integer)",
            default => "DAYOFMONTH({$column})",
        };
    }

    public static function year(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "extract(year from {$column})",
            'sqlite' => "cast(strftime('%Y', {$column}) as integer)",
            default => "YEAR({$column})",
        };
    }

    public static function month(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "extract(month from {$column})",
            'sqlite' => "cast(strftime('%m', {$column}) as integer)",
            default => "MONTH({$column})",
        };
    }

    /**
     * Truncate a timestamp to the start of its hour, day or month.
     *
     * $shiftHours moves the boundary before truncating and puts it back after,
     * which is how a day that starts at 03:00 is expressed: shift back three
     * hours, truncate to midnight, shift forward again. Passing 0 gives the
     * ordinary calendar boundary.
     *
     * The unit is an allow-list, never interpolated from caller input — this
     * lands in raw SQL.
     */
    public static function truncate(string $column, string $unit, int $shiftHours = 0): string
    {
        $unit = match ($unit) {
            'hour', 'day', 'month' => $unit,
            default => throw new \InvalidArgumentException("Unsupported truncation unit [{$unit}]."),
        };

        return match (DB::connection()->getDriverName()) {
            'pgsql' => $shiftHours === 0
                ? "date_trunc('{$unit}', {$column})"
                : "date_trunc('{$unit}', {$column} - interval '{$shiftHours} hours') + interval '{$shiftHours} hours'",
            'sqlite' => self::sqliteTruncate($column, $unit, $shiftHours),
            default => $shiftHours === 0
                ? "DATE_FORMAT({$column}, '".self::mysqlMask($unit)."')"
                : "DATE_FORMAT({$column} - INTERVAL {$shiftHours} HOUR, '".self::mysqlMask($unit)."') + INTERVAL {$shiftHours} HOUR",
        };
    }

    private static function sqliteTruncate(string $column, string $unit, int $shiftHours): string
    {
        $shifted = $shiftHours === 0 ? $column : "datetime({$column}, '-{$shiftHours} hours')";
        $mask = match ($unit) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d 00:00:00',
            'month' => '%Y-%m-01 00:00:00',
        };
        $truncated = "strftime('{$mask}', {$shifted})";

        return $shiftHours === 0 ? $truncated : "datetime({$truncated}, '+{$shiftHours} hours')";
    }

    private static function mysqlMask(string $unit): string
    {
        return match ($unit) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d 00:00:00',
            'month' => '%Y-%m-01 00:00:00',
        };
    }
}
