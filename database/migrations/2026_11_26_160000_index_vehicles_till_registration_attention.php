<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The buses whose till registration needs a person: the hourly sweep of the
 * ops board's automatic cards (App\Services\Team\AutoCards) reads exactly
 * these, and head-office cards group them by SACCO and head office.
 *
 * PARTIAL, because it is the small minority of the fleet: a registered or
 * bank-delivered bus never enters it, so the index stays a few pages however
 * many buses there are, and the sweep never walks the vehicles table. The
 * list is the union of TillRegistrationCards::FAILURES and
 * HeadOfficeCards::WAITING; a query on any subset of it still uses the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX IF NOT EXISTS vehicles_till_registration_attention_index ON vehicles (sacco_id, till_registration_status) WHERE till_registration_status IN ('refused', 'bad_keys', 'gave_up', 'needs_head_office', 'confirm_head_office', 'already_registered', 'no_keys', 'not_live')");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS vehicles_till_registration_attention_index');
    }
};
