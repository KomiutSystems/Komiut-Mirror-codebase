<?php

declare(strict_types=1);

namespace App\Services\Sql;

use Illuminate\Support\Facades\DB;

/**
 * The one definition of "these two strings are the same number plate".
 *
 * Drivers type their plate on a phone keyboard, at a stage, in a hurry, and they
 * are not technical people. "KDY 759D", "kdy759d", "KDY-759D" and "KDY.759D" are
 * the same matatu and every one of them has to sign in. Anything that is not a
 * letter or a digit is therefore noise: it is discarded on both sides of the
 * comparison and the rest is upper-cased.
 *
 * Both sides matters. This used to be split — PHP stripped whitespace with
 * `\s+` while the SQL only did REPLACE(plate, ' ', '') — so a typed hyphen
 * survived normalisation on one side and not the other, and the lookup missed a
 * vehicle that was sitting right there. Keeping the pair here is what stops the
 * write path and the login path drifting apart again.
 *
 * Deliberately NOT normalised: characters that merely LOOK alike (O/0, I/1,
 * S/5). Correcting those needs to assume a plate shape, and this fleet does not
 * have one — alongside the usual KXX 000X there are four-letter series like
 * "KTWA 463G", and the vehicles table also carries non-vehicle rows ("Parcels -
 * (Juja)", crew names) left over from the legacy import. A positional rule would
 * quietly rewrite those into each other, and a driver silently signed in to the
 * wrong matatu is far worse than one who has to retype a character.
 */
final class PlateSql
{
    /**
     * The canonical form of a plate as typed: "kdy 759d" -> "KDY759D".
     */
    public static function normalise(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/u', '', $plate));
    }

    /**
     * The same transformation, applied to a column, in whichever SQL dialect is
     * connected. Postgres and MySQL 8 / MariaDB 10.0.5+ have REGEXP_REPLACE;
     * sqlite has no regex at all without an extension, so there it falls back to
     * nested REPLACE over the punctuation that actually shows up in typed plates
     * and in the legacy data.
     */
    public static function normaliseColumn(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "upper(regexp_replace({$column}, '[^A-Za-z0-9]', '', 'g'))",
            'sqlite' => 'upper('.self::nestedReplace($column).')',
            default => "upper(regexp_replace({$column}, '[^A-Za-z0-9]', ''))",
        };
    }

    /**
     * sqlite fallback, and the one place this class is not exactly equivalent to
     * itself: nested replace() can only strip a list, where normalise() strips a
     * character *class*. Anything outside the list survives in SQL and does not
     * in PHP.
     *
     * The list therefore has to cover what production actually holds, which is
     * more than plates — the legacy import left rows like "Parcels - (Juja)",
     * "Wilson & Joseph" and "Gerald King'ori" in the vehicles table, so the
     * brackets, ampersand and apostrophe matter. Postgres and MySQL take the
     * regex branch above and have no such gap; sqlite is local/dev only.
     */
    private static function nestedReplace(string $column): string
    {
        $sql = $column;

        $strip = [
            ' ', "\t", "\n", "\r",              // whitespace
            '-', '–', '—', '_',                 // dashes a keyboard or a bank might send
            '.', ',', '/', '\\', ':', ';',      // separators
            '(', ')', '[', ']', '{', '}',       // brackets, present in the legacy rows
            '&', '#', '+', '*', '@', '!', '?',  // symbols within reach of the number row
            "'", '`', '"',                      // quotes, present in names like King'ori
        ];

        foreach ($strip as $char) {
            $sql = "replace({$sql}, '".str_replace("'", "''", $char)."', '')";
        }

        return $sql;
    }
}
