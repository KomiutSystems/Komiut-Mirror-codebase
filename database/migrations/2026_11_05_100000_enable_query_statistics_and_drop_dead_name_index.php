<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two cheap changes from the 2026-11-05 performance pass.
 *
 * QUERY STATISTICS. Every database decision so far (the 2026-10-23 incident,
 * the October audit) was made from Performance Insights samples and one-off
 * EXPLAINs, because nothing recorded which statements cost what. RDS already
 * preloads pg_stat_statements (shared_preload_libraries on komiut-prod-db,
 * checked 2026-11-05), so this is CREATE EXTENSION and nothing else: no
 * parameter group change, no reboot. Overhead is a hash lookup per statement.
 * It is what will show whether the remaining duplicate indexes on mpesas and
 * transactions (~600 MB) are read by anything before any of them is dropped.
 *
 * THE NAME INDEX ON mpesas. mpesas_firstname_middlename_lastname_index: 79 MB,
 * idx_scan = 0 for the life of the database. 2026_08_26_100000 already said why:
 * the only reader of those columns is a leading-wildcard ILIKE, which a btree
 * cannot serve (the trigram GIN indexes do), so it is pure write cost on every
 * payment inserted. It "should simply go"; this is it going.
 */
return new class extends Migration
{
    /** CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS mpesas_firstname_middlename_lastname_index');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE INDEX CONCURRENTLY IF NOT EXISTS mpesas_firstname_middlename_lastname_index ON mpesas ("FirstName", "MiddleName", "LastName")');
        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements');
    }
};
