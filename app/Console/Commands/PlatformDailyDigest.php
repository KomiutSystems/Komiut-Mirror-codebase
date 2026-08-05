<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SaccoClaimStatus;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * digest.daily (digest) — one rollup row per brand for the last 24h.
 *
 * Counts come from existing tables and from this platform's own emitted events:
 * "claimed" is the exact count of sacco.claimed audit rows written audit-first by
 * the observer; "open alerts by severity" rolls up unresolved alert notifications.
 */
class PlatformDailyDigest extends Command
{
    protected $signature = 'platform:daily-digest';

    protected $description = 'Emit a per-brand daily digest of tenant + platform activity';

    public function handle(PlatformNotifier $notifier): int
    {
        $since = now()->subDay();
        $hasBookings = Schema::hasTable('bookings');

        foreach (array_keys((array) config('brands', [])) as $brand) {
            $brand = (string) $brand;

            // Exact: audit rows we wrote audit-first when a SACCO was claimed.
            $claimed = AuditLog::where('action', 'sacco.claimed')
                ->where('brand', $brand)
                ->where('created_at', '>=', $since)
                ->count();

            $pendingDepth = Sacco::withoutGlobalScopes()
                ->where('brand', $brand)
                ->whereIn('claim_status', [SaccoClaimStatus::Directory->value, SaccoClaimStatus::PendingReview->value])
                ->count();

            // Users carry no brand column — a driver reaches its brand through the sacco.
            $drivers = (int) DB::table('users')
                ->join('saccos', 'users.sacco_id', '=', 'saccos.id')
                ->where('saccos.brand', $brand)
                ->where('users.created_at', '>=', $since)
                ->count();

            $trips = 0;
            if ($hasBookings) {
                $trips = (int) DB::table('bookings')
                    ->join('queues', 'bookings.queue_id', '=', 'queues.id')
                    ->join('vehicles', 'queues.vehicle_id', '=', 'vehicles.id')
                    ->where('vehicles.brand', $brand)
                    ->where('bookings.created_at', '>=', $since)
                    ->count();
            }

            $openAlerts = PlatformNotification::whereNull('resolved_at')
                ->where('delivery_class', 'alert')
                ->where('brand', $brand)
                ->selectRaw('severity, COUNT(*) as c')
                ->groupBy('severity')
                ->pluck('c', 'severity');

            $openBySeverity = [
                'critical' => (int) ($openAlerts['critical'] ?? 0),
                'high' => (int) ($openAlerts['high'] ?? 0),
                'normal' => (int) ($openAlerts['normal'] ?? 0),
            ];
            $openTotal = array_sum($openBySeverity);

            $notifier->dispatch(new PlatformEvent(
                event: 'digest.daily',
                severity: 'normal',
                class: 'digest',
                title: "Daily digest — {$brand}",
                summary: mb_substr(
                    "Claimed {$claimed}, pending {$pendingDepth}, new drivers {$drivers}, trips {$trips}, open alerts {$openTotal}.",
                    0,
                    140
                ),
                brand: $brand,
                data: [
                    'brand' => $brand,
                    'saccosClaimed' => $claimed,
                    'pendingReviewDepth' => $pendingDepth,
                    'driversOnboarded' => $drivers,
                    'trips' => $trips,
                    'openAlertsBySeverity' => $openBySeverity,
                ],
                dedupeKey: 'digest.daily:'.$brand,
                windowMinutes: 20 * 60, // one per brand per day
            ));
        }

        $this->info('Daily digest emitted.');

        return self::SUCCESS;
    }
}
