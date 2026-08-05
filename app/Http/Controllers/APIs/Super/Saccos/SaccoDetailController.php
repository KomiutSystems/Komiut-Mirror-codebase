<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Saccos;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\LoyaltyProgram;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\SaccoRoute;
use App\Models\SaccoUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * The single-SACCO detail view for the super-admin console. Cross-brand — see
 * SaccosController's docblock. Gated by `View Platform Notifications` at the
 * route.
 */
class SaccoDetailController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $sacco = Sacco::findOrFail($id);

        $grossVolume30d = (float) Transaction::whereHas('vehicle', fn ($q) => $q->where('sacco_id', $sacco->id))
            ->where('trans_date', '>=', now()->subDays(30))
            ->sum('amount');

        $unreconciled = Transaction::whereHas('vehicle', fn ($q) => $q->where('sacco_id', $sacco->id))
            ->where('summarized', false)
            ->where('trans_date', '>=', now()->subDays(30))
            ->count();

        $loyalty = LoyaltyProgram::where('sacco_id', $sacco->id)->first();

        return response()->json([
            'id' => $sacco->id,
            'name' => $sacco->name,
            'slogan' => $sacco->slogan,
            'email' => $sacco->email,
            'phone' => $sacco->phone,
            'brand' => $sacco->brand,
            'status' => $sacco->status,
            'claim_status' => $sacco->claim_status?->value,
            'created_at' => optional($sacco->created_at)->toIso8601String(),
            'claimed_at' => optional($this->claimedAt($sacco))->toIso8601String(),
            'rotates_drivers' => $sacco->rotates_drivers,
            'counts' => [
                'members' => SaccoUser::where('sacco_id', $sacco->id)->whereNull('end_date')->count(),
                'drivers' => User::where('sacco_id', $sacco->id)->where('type', UserType::Driver)->count(),
                'vehicles' => Vehicle::where('sacco_id', $sacco->id)->count(),
                'routes' => SaccoRoute::where('sacco_id', $sacco->id)->distinct('route_id')->count('route_id'),
                'trips_30d' => Booking::whereHas('queue.vehicle', fn ($q) => $q->where('sacco_id', $sacco->id))
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'money' => [
                'gross_volume_30d' => $grossVolume30d,
                'currency' => 'KES',
                'unreconciled' => $unreconciled,
            ],
            'loyalty' => [
                'is_active' => $loyalty?->is_active,
                'divisor' => $loyalty?->divisor,
                'redemption_threshold' => $loyalty?->redemption_threshold,
            ],
            'recent_events' => $this->recentEvents($sacco),
        ]);
    }

    /**
     * Best-effort claim timestamp: `verified_at` is set the moment a SACCO is
     * claimed (see SaccoDirectory::markClaimed and DirectoryController::approve),
     * so it is the primary source; the sacco.claimed audit row (subject_id is the
     * SACCO id as a string — see SaccoObserver::emitClaimed) is the fallback for
     * rows claimed before verified_at existed.
     */
    private function claimedAt(Sacco $sacco): ?Carbon
    {
        if ($sacco->verified_at !== null) {
            return $sacco->verified_at;
        }

        $audit = AuditLog::where('action', 'sacco.claimed')
            ->where('subject_id', (string) $sacco->id)
            ->latest('created_at')
            ->first();

        return $audit?->created_at;
    }

    /**
     * The last 10 sacco.* console events for this SACCO. Every sacco.* emitter
     * (SaccoObserver, DirectoryController) writes an int `saccoId` into `data`,
     * so a JSON-path match on it is reliable across both the sqlite test DB and
     * the mysql production DB.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentEvents(Sacco $sacco): array
    {
        return PlatformNotification::where('event', 'LIKE', 'sacco.%')
            ->where('data->saccoId', $sacco->id)
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (PlatformNotification $n): array => [
                'id' => $n->id,
                'event' => $n->event,
                'title' => $n->title,
                'occurred_at' => optional($n->occurred_at)->toIso8601String(),
            ])
            ->all();
    }
}
