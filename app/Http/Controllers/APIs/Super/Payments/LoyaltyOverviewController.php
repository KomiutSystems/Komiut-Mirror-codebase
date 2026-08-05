<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Payments;

use App\Enums\LoyaltyTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Services\Platform\Thresholds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Super-admin console — cross-brand loyalty program overview, one row per SACCO
 * that has a LoyaltyProgram. Reuses the same "extreme config" floor
 * LoyaltyProgramObserver alerts on (App\Services\Platform\Thresholds,
 * loyalty_divisor_floor) so `below_floor` here means the same thing it does in
 * that alert.
 *
 * earn_failure_rate is best-effort: LoyaltyEarnFailureTracker's rolling counters
 * (app/Services/Super/Money/LoyaltyEarnFailureTracker.php) are only cheaply
 * readable per BRAND (its cache key has no SACCO dimension), so every SACCO
 * sharing a brand reports the same rate here — a coarser signal than a true
 * per-SACCO failure rate would be, but reads the same live counters the alert
 * itself trips on rather than inventing a second source of truth.
 */
final class LoyaltyOverviewController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $validated = $request->validate([
            'brand' => 'nullable|string',
            'sacco_id' => 'nullable|integer',
            'active' => 'nullable|boolean',
            'below_floor' => 'nullable|boolean',
            'per_page' => 'nullable|integer',
        ]);

        $programs = LoyaltyProgram::query()
            ->with('sacco:id,name,brand')
            ->when($request->filled('sacco_id'), fn ($q) => $q->where('sacco_id', $request->input('sacco_id')))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('brand'), fn ($q) => $q->whereHas('sacco', fn ($sq) => $sq->where('brand', $request->input('brand'))))
            ->get();

        $windowStart = now()->subDays(30);
        $rateCache = [];

        $rows = $programs->map(function (LoyaltyProgram $program) use ($windowStart, &$rateCache): array {
            $sacco = $program->sacco;
            $brand = $sacco?->brand;
            $floor = (float) (Thresholds::get($brand, 'loyalty_divisor_floor') ?? 0);

            $issued = LoyaltyTransaction::where('sacco_id', $program->sacco_id)
                ->where('type', LoyaltyTransactionType::Earned->value)
                ->where('created_at', '>=', $windowStart)
                ->sum('value');
            $redeemed = LoyaltyTransaction::where('sacco_id', $program->sacco_id)
                ->where('type', LoyaltyTransactionType::Redeemed->value)
                ->where('created_at', '>=', $windowStart)
                ->sum('value');

            $cacheKey = $brand ?? '_';
            if (! array_key_exists($cacheKey, $rateCache)) {
                $state = Cache::get('super:money:loyalty_earn:'.$cacheKey);
                $attempts = (int) ($state['attempts'] ?? 0);
                $failures = (int) ($state['failures'] ?? 0);
                $rateCache[$cacheKey] = $attempts > 0 ? round($failures / $attempts, 4) : 0.0;
            }

            return [
                'sacco' => $sacco !== null ? ['id' => $sacco->id, 'name' => $sacco->name] : null,
                'brand' => $brand,
                'is_active' => (bool) $program->is_active,
                'divisor' => (float) $program->divisor,
                'redemption_threshold' => (float) $program->redemption_threshold,
                'points_issued_30d' => (float) $issued,
                'points_redeemed_30d' => abs((float) $redeemed),
                'below_floor' => (float) $program->divisor < $floor,
                'earn_failure_rate' => $rateCache[$cacheKey],
                'updated_at' => optional($program->updated_at)->toIso8601String(),
            ];
        });

        if ($request->has('below_floor')) {
            $wantBelow = $request->boolean('below_floor');
            $rows = $rows->filter(fn (array $r) => $r['below_floor'] === $wantBelow)->values();
        }

        $perPage = max(1, min((int) ($validated['per_page'] ?? 25), 100));
        $page = max(1, (int) $request->input('page', 1));
        $total = $rows->count();
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page);

        return SlimPage::of($paginator);
    }
}
