<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event 6 — sacco.dormant (digest / normal, weekly per SACCO).
 *
 * A claimed SACCO with no trips/payments for `sacco_dormant_days`. Activity =
 * bookings reached through queues.vehicle_id → vehicles.sacco_id; a paid booking
 * is the payment signal too, so "no bookings" covers both trips and payments. A
 * claimed SACCO that never carried a trip falls back to verified_at / created_at.
 */
class DetectDormantSaccos extends Command
{
    protected $signature = 'sacco:detect-dormant';

    protected $description = 'Flag claimed SACCOs with no trips/payments beyond the dormancy threshold';

    public function handle(PlatformNotifier $notifier): int
    {
        if (! Schema::hasTable('bookings')) {
            $this->warn('bookings table missing — skipping.');

            return self::SUCCESS;
        }

        $now = now();
        $flagged = 0;

        // Cross-brand: console context isn't brand-scoped, so this sees every brand.
        Sacco::withoutGlobalScopes()
            ->where('claim_status', SaccoClaimStatus::Claimed->value)
            ->orderBy('id')
            ->chunkById(200, function ($saccos) use ($notifier, $now, &$flagged): void {
                foreach ($saccos as $sacco) {
                    $days = (int) Thresholds::get($sacco->brand, 'sacco_dormant_days');

                    $lastBooking = DB::table('bookings')
                        ->join('queues', 'bookings.queue_id', '=', 'queues.id')
                        ->join('vehicles', 'queues.vehicle_id', '=', 'vehicles.id')
                        ->where('vehicles.sacco_id', $sacco->id)
                        ->max('bookings.created_at');

                    $lastActivity = $lastBooking !== null
                        ? Carbon::parse($lastBooking)
                        : ($sacco->verified_at ?? $sacco->created_at);

                    if ($lastActivity === null) {
                        continue; // no baseline at all — nothing meaningful to report
                    }

                    $daysInactive = (int) $lastActivity->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());
                    if ($daysInactive < $days) {
                        continue;
                    }

                    $notifier->dispatch(new PlatformEvent(
                        event: 'sacco.dormant',
                        severity: 'normal',
                        class: 'digest',
                        title: 'SACCO dormant',
                        summary: mb_substr("\"{$sacco->name}\" inactive {$daysInactive}d (threshold {$days}d).", 0, 140),
                        brand: $sacco->brand,
                        subject: ['type' => 'sacco', 'id' => $sacco->id],
                        data: [
                            'saccoId' => $sacco->id,
                            'name' => $sacco->name,
                            'daysInactive' => $daysInactive,
                            'lastActivityAt' => $lastActivity->toIso8601String(),
                        ],
                        dedupeKey: 'sacco.dormant:'.$sacco->id,
                        windowMinutes: 7 * 24 * 60, // weekly per SACCO
                    ));

                    $flagged++;
                }
            });

        $this->info("Dormant SACCO check complete — {$flagged} flagged.");

        return self::SUCCESS;
    }
}
