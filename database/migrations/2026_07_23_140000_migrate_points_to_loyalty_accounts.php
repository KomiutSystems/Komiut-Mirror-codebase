<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time back-fill: materialise the legacy `points` balances (points − redeemed)
 * into the new loyalty_accounts, keyed by (user_id, sacco_id). Legacy rows with no
 * user or no sacco are skipped — the new model is registered-user + per-SACCO. The
 * old points/point_settings tables are left in place for history; their earn cron
 * is unscheduled in the console kernel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('points') || ! Schema::hasTable('loyalty_accounts')) {
            return;
        }

        $balances = DB::table('points')
            ->selectRaw('user_id, sacco_id, SUM(points - redeemed) as balance')
            ->whereNotNull('user_id')
            ->whereNotNull('sacco_id')
            ->groupBy('user_id', 'sacco_id')
            ->havingRaw('SUM(points - redeemed) > 0')
            ->get();

        $now = now();
        foreach ($balances as $row) {
            DB::table('loyalty_accounts')->updateOrInsert(
                ['user_id' => $row->user_id, 'sacco_id' => $row->sacco_id],
                ['balance' => (float) $row->balance, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // Carry each SACCO's earn divisor over from the legacy "by amount" point
        // settings, so earning keeps working. redemption_threshold starts at 0
        // (redeeming stays off until a SACCO sets it) — safe by default.
        if (Schema::hasTable('point_settings') && Schema::hasTable('loyalty_programs')) {
            $settings = DB::table('point_settings')
                ->where('status', true)
                ->where('points_type', 'by amount')
                ->whereNotNull('sacco_id')
                ->whereNotNull('amount')
                ->where('amount', '>', 0)
                ->get(['sacco_id', 'amount']);

            foreach ($settings->groupBy('sacco_id') as $saccoId => $group) {
                DB::table('loyalty_programs')->updateOrInsert(
                    ['sacco_id' => $saccoId],
                    [
                        'divisor' => (float) $group->first()->amount,
                        'redemption_threshold' => 0,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        // Keep migrated balances; nothing to reverse.
    }
};
