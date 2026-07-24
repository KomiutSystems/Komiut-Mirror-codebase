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
}
