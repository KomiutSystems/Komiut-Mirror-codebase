<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `saccos` into a DIRECTORY of names, not just a table of customers.
 *
 * Drivers are onboarded on the ground before their SACCO has signed up, so most
 * rows here are reference data with no account behind them. `claim_status` says
 * which kind a row is (see App\Enums\SaccoClaimStatus), `source` where the name
 * came from (sasra, ntsa, driver_submitted, manual, self_registered) and
 * `verified_at` when a human confirmed it.
 *
 * Plain strings rather than DB enums: the value sets will grow, and altering an
 * enum type on Postgres is a migration-time lock we don't want.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saccos', function (Blueprint $table): void {
            $table->string('claim_status')->default('directory')->after('status');
            $table->string('source')->nullable()->after('claim_status');
            $table->timestamp('verified_at')->nullable()->after('source');
            $table->index('claim_status');
        });

        $this->backfill();
    }

    /**
     * Classify the rows that predate the directory.
     *
     * Before this, the only way a sacco existed was self-registration, which set
     * an email and created an admin user — so an email is the marker of a row
     * that already has a login. Everything else becomes a plain directory entry.
     *
     * Public so the suite can exercise it against seeded rows: under
     * RefreshDatabase the migration itself only ever runs on an empty database.
     * Query builder, not Eloquent — this must see every brand's rows.
     */
    public function backfill(): void
    {
        if (! Schema::hasColumn('saccos', 'email')) {
            return;
        }

        DB::table('saccos')->whereNotNull('email')->update([
            'claim_status' => 'claimed',
            'source' => 'self_registered',
        ]);

        DB::table('saccos')->whereNull('email')->update([
            'claim_status' => 'directory',
        ]);
    }

    public function down(): void
    {
        Schema::table('saccos', function (Blueprint $table): void {
            $table->dropIndex(['claim_status']);
            $table->dropColumn(['claim_status', 'source', 'verified_at']);
        });
    }
};
