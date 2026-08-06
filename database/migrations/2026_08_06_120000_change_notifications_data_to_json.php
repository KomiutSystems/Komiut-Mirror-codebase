<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * notifications.data holds a JSON payload but was created as `text`, while its
 * siblings platform_notifications.data and audit_logs.data are real `json`.
 *
 * The code queries it by JSON path — NotificationService reads data->referenceId
 * and data->title to suppress duplicates, NotificationsController filters on
 * data->type. MySQL and sqlite apply those paths to a text column happily;
 * PostgreSQL has no `text ->> unknown` operator and errors, so every one of
 * those reads was a 500 waiting for the first Postgres deploy.
 *
 * Written as an ALTER rather than an edit to the original migration because
 * staging has already run it, and a change only fresh installs would pick up
 * leaves the environment that actually matters on the broken type.
 *
 * PostgreSQL will not implicitly reinterpret text as json, so the USING clause
 * is required; MySQL needs a plain MODIFY; sqlite has no strict column types
 * and both the old and new declarations behave identically, so it is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data JSON NOT NULL'),
            default => null, // sqlite: dynamically typed, nothing to change.
        };
    }

    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data TEXT NOT NULL'),
            default => null,
        };
    }
};
