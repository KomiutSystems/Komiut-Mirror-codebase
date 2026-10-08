<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "When did a payment last arrive through this connection, and how many this
 * week" -- per connection, on the M-Pesa connections page. mpesas is ~7M rows
 * and only had mpesa_setting_id alone, so MAX("TransTime") walked every row a
 * busy connection ever received. (mpesa_setting_id, "TransTime") makes both an
 * index range. Built CONCURRENTLY: payments keep landing while it builds.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS mpesas_setting_transtime_index ON mpesas (mpesa_setting_id, "TransTime")');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS mpesas_setting_transtime_index');
    }
};
