<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the passenger loyalty screen something to show.
 *
 * One SACCO on the whole platform had a loyalty program — NICCO, configured
 * 2026-08-08 — so even after the summary endpoint stopped hiding zero-balance
 * cards there was exactly one card to draw. A rewards screen with a single
 * SACCO on it reads as broken rather than as a scheme worth joining.
 *
 * Chosen by LIVE FLEET SIZE among active SACCOs rather than by hardcoded id:
 * the ids differ between prod, staging and a fresh test database, and a list of
 * literals would silently enrol the wrong SACCOs (or none) everywhere but the
 * box it was written on. Biggest fleets first because those are the SACCOs a
 * passenger is most likely to actually ride and therefore earn on.
 *
 * DEFAULTS ARE A STARTING POINT, NOT A DECISION. divisor 100 matches the one
 * program that already existed (1 point per KES 100 spent); redemption_threshold
 * 50 means a free ride after roughly KES 5,000 of travel. Each SACCO can change
 * both from the dashboard — POST saccos/loyalty/save — and this migration never
 * touches a SACCO that already has a program, so nobody's existing settings are
 * overwritten.
 */
return new class extends Migration
{
    /** How many SACCOs should be offering rewards once this has run. */
    private const TARGET = 5;

    public function up(): void
    {
        $active = DB::table('loyalty_programs')->where('is_active', true)->count();

        if ($active >= self::TARGET) {
            return;
        }

        // Never re-enrol a SACCO that already has a program, active or not —
        // switching one back on is the SACCO's call, not a migration's.
        $enrolled = DB::table('loyalty_programs')->pluck('sacco_id')->all();

        $candidates = DB::table('saccos')
            ->leftJoin('vehicles', 'vehicles.sacco_id', '=', 'saccos.id')
            ->where('saccos.status', 1)
            ->when($enrolled !== [], fn ($q) => $q->whereNotIn('saccos.id', $enrolled))
            ->groupBy('saccos.id')
            ->orderByRaw('COUNT(vehicles.id) DESC')
            ->orderBy('saccos.id')
            ->limit(self::TARGET - $active)
            ->pluck('saccos.id');

        $now = now();

        foreach ($candidates as $saccoId) {
            DB::table('loyalty_programs')->insert([
                'sacco_id' => (int) $saccoId,
                'divisor' => 100,
                'redemption_threshold' => 50,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Deliberately not reversible.
     *
     * By the time anyone rolls back, these programs may have accrued real
     * balances and a real ledger. Deleting the program row would cascade nothing
     * but would strand every account and transaction pointing at it, and a
     * passenger's earned points are not something a schema rollback should
     * destroy. Turning a program off is a dashboard action.
     */
    public function down(): void
    {
        // no-op, by design
    }
};
