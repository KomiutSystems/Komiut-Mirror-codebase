<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which bank a user reads on behalf of — the user-side half of the financier
 * boundary that `vehicles.financier` already carries.
 *
 * NCBA and Co-op staff log into the dashboard as real users and must see only
 * the fleet their own bank financed. Nothing already on `users` can express
 * that. `sacco_id` cannot: NICCO MOVERS holds 126 NCBA vehicles and 54 Co-op
 * ones, so pinning a bank to that SACCO shows each of them the other's buses.
 * `brand` cannot either — users carry no brand column at all, and brand is
 * resolved from the request rather than from the account.
 *
 * Nullable, and NULL is the overwhelming default: every one of the platform's
 * users except a handful of bank staff is not a bank. Read
 * App\Models\Scopes\FinancierScope for what a set value does — in short, it
 * narrows that account to one bank's vehicles and fails closed if the value
 * cannot be resolved.
 *
 * A CHECK constraint rather than free text, because unlike `vehicles.financier`
 * (which ImportLegacyVehicles still writes verbatim from legacy data, unknown
 * values included) nothing backfills this column: every value is set
 * deliberately by a superadmin, so a typo here is a bank looking at an empty
 * dashboard with no error to explain it. The allowed values are the backing
 * values of App\Enums\Financier, written out rather than imported — a
 * migration is a historical record and must not change meaning if the enum
 * later gains a case.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'users_financier_check';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'financier')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('financier', 60)->nullable()->after('sacco_id');
            });
        }

        if (DB::getDriverName() !== 'pgsql' || $this->constraintExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT ' . self::CONSTRAINT .
            " CHECK (financier IS NULL OR financier IN ('NCBA', 'coop-bank'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && $this->constraintExists()) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT ' . self::CONSTRAINT);
        }

        if (Schema::hasColumn('users', 'financier')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('financier');
            });
        }
    }

    /** PostgreSQL has no ADD CONSTRAINT IF NOT EXISTS, so ask first. */
    private function constraintExists(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ?', [self::CONSTRAINT]
        ) !== null;
    }
};
