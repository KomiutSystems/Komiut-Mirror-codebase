<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Pulse;

use App\Brands\BrandRegistry;
use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformNotification;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Cache\ScopedCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The super-admin overview dashboard — a single cheap snapshot of the whole
 * platform, cross-brand. Every figure here is a fast aggregate query; nothing
 * here should walk trip history row-by-row (see the `dormant` note below).
 */
class PulseController extends Controller
{
    /** Money figures: short enough that nobody acts on a stale number. */
    private const SNAPSHOT_TTL = 60;

    public function index(Request $request): JsonResponse
    {
        $range = in_array($request->input('range'), ['24h', '7d', '30d'], true)
            ? $request->input('range')
            : '7d';

        $end = now();
        $start = $range === '24h'
            ? $end->copy()->subHours(24)
            : $end->copy()->subDays($range === '30d' ? 30 : 7)->startOfDay();

        // "Prior equal period" — same length window immediately before $start,
        // whatever that length turned out to be for the chosen range.
        $periodSeconds = max(1, $start->diffInSeconds($end));
        $prevEnd = $start->copy();
        $prevStart = $prevEnd->copy()->subSeconds($periodSeconds);

        // 60s. This is a situational-awareness dashboard, not a ledger: it is
        // read repeatedly, and every field is an aggregate over tables in the
        // tens of millions of rows. A minute of staleness is invisible to a
        // human watching a trend; re-running the whole snapshot per page load
        // is not. Deliberately short because these are money figures — long
        // enough to shed load, short enough that nobody acts on a stale number.
        //
        // Reconciliation and alerting must NOT be cached this way; they are
        // correctness-critical and read elsewhere.
        $payload = ScopedCache::remember(
            'super:pulse',
            ['range' => $range],
            self::SNAPSHOT_TTL,
            fn (): array => [
                'range' => $range,
                'saccos' => $this->saccos(),
                'people' => $this->people(),
                'fleet' => $this->fleet(),
                'money' => $this->money($start, $end, $prevStart, $prevEnd),
                'trips' => $this->trips($start, $end),
                'alerts' => $this->alerts(),
                'volume_series' => $this->volumeSeries($start, $end),
                'by_brand' => $this->byBrand($start, $end),
            ],
        );

        return response()->json($payload);
    }

    private function saccos(): array
    {
        return [
            'total' => Sacco::count(),
            'claimed' => Sacco::where('claim_status', SaccoClaimStatus::Claimed->value)->count(),
            'pending_review' => Sacco::where('claim_status', SaccoClaimStatus::PendingReview->value)->count(),
            // Best-effort: the open (unresolved) sacco.dormant notification count,
            // not a fresh trip-history scan — DetectDormantSaccos already does that
            // walk weekly and leaves the result sitting in platform_notifications.
            'dormant' => PlatformNotification::where('event', 'sacco.dormant')
                ->whereNull('resolved_at')->count(),
        ];
    }

    private function people(): array
    {
        return [
            'users' => User::count(),
            'drivers' => User::where('type', UserType::Driver->value)->count(),
            'active_today' => User::where('last_active_at', '>=', now()->startOfDay())->count(),
        ];
    }

    private function fleet(): array
    {
        $activeStatusIds = QueueStatus::where('status', 'Active')->pluck('id');

        return [
            'total' => Vehicle::count(),
            'on_road_now' => Queue::whereIn('queue_status_id', $activeStatusIds)
                ->distinct('vehicle_id')->count('vehicle_id'),
            'capacity_seats' => (int) DB::table('vehicles')
                ->join('seats', 'seats.id', '=', 'vehicles.seat_id')
                ->sum('seats.seats'),
        ];
    }

    private function money(Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        // attributed() throughout: platform volume counts money that reached a
        // bus. This view is unscoped, so without it the SACCOs' nightly
        // till-to-bank sweeps — which arrive as C2B on collection shortcodes
        // belonging to no vehicle — were being reported as platform revenue.
        $grossVolume = (float) Transaction::attributed()->whereBetween('trans_date', [$start, $end])->sum('amount');
        $transactions = Transaction::attributed()->whereBetween('trans_date', [$start, $end])->count();
        $prevGrossVolume = (float) Transaction::attributed()->whereBetween('trans_date', [$prevStart, $prevEnd])->sum('amount');

        if ($prevGrossVolume > 0) {
            $changePct = round((($grossVolume - $prevGrossVolume) / $prevGrossVolume) * 100, 1);
        } else {
            $changePct = $grossVolume > 0 ? 100.0 : 0.0;
        }

        $trend = $grossVolume > $prevGrossVolume ? 'up' : ($grossVolume < $prevGrossVolume ? 'down' : 'flat');

        return [
            'gross_volume' => $grossVolume,
            'currency' => 'KES',
            'transactions' => $transactions,
            // Cheap source: the open (unresolved) payment.reconciliation.failed
            // notification count, mirroring the `dormant` approach above rather
            // than re-deriving reconciliation state here.
            'failed_reconciliations' => PlatformNotification::where('event', 'payment.reconciliation.failed')
                ->whereNull('resolved_at')->count(),
            'trend' => $trend,
            'change_pct' => $changePct,
        ];
    }

    private function trips(Carbon $start, Carbon $end): array
    {
        $completedStatusIds = QueueStatus::where('status', 'Completed')->pluck('id');
        $cancelledStatusIds = QueueStatus::where('status', 'Cancelled')->pluck('id');

        return [
            'completed' => Queue::whereIn('queue_status_id', $completedStatusIds)
                ->whereBetween('updated_at', [$start, $end])->count(),
            'bookings' => Booking::whereBetween('created_at', [$start, $end])->count(),
            'cancelled' => Queue::whereIn('queue_status_id', $cancelledStatusIds)
                ->whereBetween('updated_at', [$start, $end])->count(),
        ];
    }

    /** The SAME 3-bucket logic as PlatformNotificationController::unreadCount. */
    private function alerts(): array
    {
        $base = PlatformNotification::whereNull('read_at');

        return [
            'critical' => (clone $base)->where('delivery_class', 'alert')->where('severity', 'critical')->count(),
            'high' => (clone $base)->where('delivery_class', 'alert')->where('severity', 'high')->count(),
            'review' => (clone $base)->where('delivery_class', 'review')->count(),
        ];
    }

    /** Daily gross volume across ALL brands, zero-filled for days with no transactions. */
    private function volumeSeries(Carbon $start, Carbon $end): array
    {
        $dateExpr = match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char(trans_date, 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', trans_date)",
            default => "DATE_FORMAT(trans_date, '%Y-%m-%d')",
        };

        $byDay = Transaction::attributed()
            ->selectRaw("{$dateExpr} as d, SUM(amount) as total")
            ->whereBetween('trans_date', [$start, $end])
            ->groupBy(DB::raw($dateExpr))
            ->pluck('total', 'd');

        $series = [];
        $cursor = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();
        while ($cursor->lte($lastDay)) {
            $key = $cursor->format('Y-m-d');
            $series[] = [$key, (float) ($byDay[$key] ?? 0)];
            $cursor->addDay();
        }

        return $series;
    }

    /** Per-brand rollup from the static brand catalog — cheap, no join fan-out. */
    private function byBrand(Carbon $start, Carbon $end): array
    {
        $out = [];
        foreach (array_keys(app(BrandRegistry::class)->all()) as $key) {
            $out[] = [
                'brand' => $key,
                'saccos' => Sacco::where('brand', $key)->count(),
                'trips' => Queue::whereHas('vehicle', fn ($q) => $q->where('brand', $key))
                    ->whereBetween('created_at', [$start, $end])->count(),
                'gross_volume' => (float) Transaction::whereHas('vehicle', fn ($q) => $q->where('brand', $key))
                    ->whereBetween('trans_date', [$start, $end])->sum('amount'),
            ];
        }

        return $out;
    }
}
