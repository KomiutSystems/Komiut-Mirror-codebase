<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three indexes on `mpesas` that another index already answers.
 *
 * `mpesas` is the biggest table on the platform -- 8.54M rows, 4,177 MB, of
 * which 2,539 MB is indexes. Measured 2026-11-09, three of them are a strict
 * PREFIX of a wider index on the same columns in the same order, so Postgres
 * can serve every lookup they serve from the wider one:
 *
 *   ("TransTime")                     <  ("TransTime", id)
 *   ("BusinessShortCode")             <  ("BusinessShortCode", "TransTime")
 *   (mpesa_setting_id)                <  (mpesa_setting_id, "TransTime")
 *
 * This is the follow-up 2026_08_27_140000_add_money_keyset_indexes promised
 * when it built ("TransTime", id) and left the single-column one alone:
 * "dropping a live index belongs in its own change, where it can be reverted
 * on its own". This is that change, and down() puts all three back.
 *
 * 372 MB back, and ~2.9M inserts a day stop maintaining three extra B-trees.
 * The cache is what this is really about: the working set is 8.4 GB against
 * 2 GB of RAM on db.t4g.small, so every megabyte of dead index is a megabyte
 * of live data that cannot stay resident.
 *
 * THE FOURTH ONE STAYS. `mpesas_setting_transtime_index` (259 MB, the biggest
 * of the four and only 42 scans) looks like the best candidate and is the one
 * that must not go: it is the SUPERSET of the pair above, so dropping both
 * halves would leave `mpesa_setting_id` with no index at all and send its 329
 * scans to a sequential read of 8.54M rows. Between two redundant indexes,
 * keep the wider one -- it can only ever answer more.
 *
 * Still worth knowing: mpesa_setting_id is NULL on 5.48M of the 8.54M rows,
 * so the index that stays is about two thirds dead weight. Rebuilding it as
 * partial (WHERE mpesa_setting_id IS NOT NULL) would reclaim perhaps another
 * 160 MB and stay transparent for equality lookups, which imply NOT NULL.
 * Deliberately NOT done here: it would make an unqualified scan or an
 * `IS NULL` lookup fall off the index, and nothing has measured whether one
 * exists. A separate change, with its own evidence.
 */
return new class extends Migration
{
    /**
     * NOT in a transaction: DROP INDEX CONCURRENTLY cannot run inside one, and
     * a plain DROP INDEX takes ACCESS EXCLUSIVE on `mpesas` -- which blocks the
     * C2B confirmation insert, i.e. live payment ingest.
     */
    public $withinTransaction = false;

    /** Index => the wider index that already answers everything it answers. */
    private const REDUNDANT = [
        'mpesas_transtime_index' => 'mpesas_transtime_id_index',
        'mpesas_businessshortcode_index' => 'mpesas_shortcode_transtime_index',
        'mpesas_mpesa_setting_id_index' => 'mpesas_setting_transtime_index',
    ];

    /** Rebuild definitions for down(), kept beside the drops so they cannot drift. */
    private const COLUMNS = [
        'mpesas_transtime_index' => '("TransTime")',
        'mpesas_businessshortcode_index' => '("BusinessShortCode")',
        'mpesas_mpesa_setting_id_index' => '(mpesa_setting_id)',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('mpesas')) {
            return;
        }

        foreach (self::REDUNDANT as $index => $keptInstead) {
            // Refuse to drop one whose replacement is not actually there. A
            // half-applied earlier migration, or a hand-dropped index, would
            // otherwise leave the column with nothing.
            if (! $this->indexExists($keptInstead)) {
                throw new RuntimeException(
                    "Refusing to drop {$index}: its superset {$keptInstead} is missing, so the column would be left unindexed."
                );
            }

            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('mpesas')) {
            return;
        }

        foreach (self::COLUMNS as $index => $columns) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON mpesas USING btree {$columns}");
        }
    }

    private function indexExists(string $name): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'mpesas')
            ->where('indexname', $name)
            ->exists();
    }
};
