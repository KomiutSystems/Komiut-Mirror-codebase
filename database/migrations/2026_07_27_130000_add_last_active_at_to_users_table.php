<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `last_active_at` — when this account last made an authenticated request.
 *
 * Distinct from `updated_at`, which means "the record was edited". Stamped by
 * TouchLastActive on every authenticated request (throttled), so a SACCO can
 * see which of its drivers/staff are actually using the system — and, paired
 * with the vehicle assignment, who was last behind the wheel of a given matatu.
 *
 * Indexed because the dashboards sort and filter on it ("active in last 7 days").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_active_at')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_active_at');
        });
    }
};
