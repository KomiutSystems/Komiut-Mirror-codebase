<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Vehicles;

use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Super-admin console — which buses take the most money.
 *
 * Built for the "individual drivers" view: every bus outside NICCO, ranked.
 * NICCO is not written in here. The console passes it as exclude_sacco_id[],
 * so the next large fleet can be set aside the same way without a deploy.
 *
 * RANKED BY TAKINGS PER WORKING DAY, among buses that worked enough days.
 * A 30-day total favours a bus that was on the platform all month over one that
 * joined three weeks in and out-earns it every day. A per-day figure on its own
 * is worse: in October 2026 a Likana bus had one KES 56,000 deposit and nothing
 * else, which made it the "best" bus on the platform. So buses with fewer than
 * min_active_days days of takings (default: a quarter of the window) are left
 * out of the ranking, and counted in meta.below_min_days rather than dropped
 * without a word. min_active_days=1 shows every bus.
 *
 * READ FROM `summaries`, NOT `transactions`. The per-bus daily totals are kept
 * current every five minutes (app:generate-vehicle-summaries), and the SACCO
 * Summaries page already reads them. Grouping the raw transactions instead was
 * measured on production on 2026-10-30: 6.2s for 30 days and 11.9s for 90, a
 * parallel scan of the whole table from disk with the sort spilling to temp
 * files. The same 30 days from summaries took 19ms, for the same 191 buses and
 * the same working-day counts, with totals within KES 240 of KES 72.9M. The
 * cost of that choice: a day here is a CALENDAR day, as summaries and the
 * Summaries page count it, not the 03:00-03:00 business day the driver app uses.
 * A bus's after-midnight fares land on the next date. For a month's ranking
 * that does not move anyone.
 */
final class VehiclePerformanceController extends Controller
{
    private const MAX_DAYS = 90;

    private const DEFAULT_DAYS = 30;

    private const SORTS = ['kes_per_day', 'kes', 'payments', 'active_days', 'last_active_date'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'exclude_sacco_id' => 'nullable|array',
            'exclude_sacco_id.*' => 'integer',
            'sacco_id' => 'nullable|integer',
            'brand' => 'nullable|string',
            'q' => 'nullable|string|max:60',
            'sort' => 'nullable|in:'.implode(',', self::SORTS),
            'min_active_days' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
        ]);

        [$from, $to] = $this->window($validated);
        $days = (int) $from->diffInDays($to) + 1;
        $sort = $validated['sort'] ?? 'kes_per_day';
        $minDays = (int) ($validated['min_active_days'] ?? max(1, intdiv($days, 4)));

        $matching = $this->filtered($this->rows($from, $to), $validated);
        [$ranked, $below] = $matching->partition(fn (array $r): bool => $r['active_days'] >= $minDays);

        $ranked = $ranked->sort(fn (array $a, array $b): int => [$b[$sort], $b['kes']] <=> [$a[$sort], $a['kes']])->values();

        $perPage = max(1, min((int) ($validated['per_page'] ?? 25), 100));
        $page = (int) ($validated['page'] ?? 1);
        $offset = ($page - 1) * $perPage;
        $slice = $ranked->slice($offset, $perPage)->values()
            ->map(fn (array $r, int $i): array => ['rank' => $offset + $i + 1] + $r);

        // SlimPage's envelope with meta beside it. JsonResource::additional()
        // would wrap the whole envelope in a second "data".
        $envelope = SlimPage::of(new LengthAwarePaginator($slice, $ranked->count(), $perPage, $page))->toArray($request);

        return response()->json($envelope + ['meta' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'sort' => $sort,
            'min_active_days' => $minDays,
            // Buses with takings in the window that this ranking leaves out
            // for working fewer than min_active_days days.
            'below_min_days' => $below->count(),
            // Everything the filters matched, ranked or not, so the header
            // reads the same whatever min_active_days is.
            'totals' => [
                'buses' => $matching->count(),
                'payments' => $matching->sum('payments'),
                'kes' => round($matching->sum('kes'), 2),
            ],
        ]]);
    }

    /**
     * The dates asked for, defaulting to the 30 days up to today (Nairobi).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $validated): array
    {
        $tz = BusinessDay::TIMEZONE;
        $to = isset($validated['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['to'], $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfDay();
        $from = isset($validated['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['from'], $tz)->startOfDay()
            : $to->subDays(self::DEFAULT_DAYS - 1);

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'from must be on or before to.']);
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'The window can be at most '.self::MAX_DAYS.' days.']);
        }

        return [$from, $to];
    }

    /**
     * One row per bus that took money in the window.
     *
     * DB::table on purpose: the console reads across every brand, SACCO and
     * bank, and this must not depend on which scopes the caller is exempt from.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('summaries')
            ->join('vehicles', 'vehicles.id', '=', 'summaries.vehicle_id')
            ->leftJoin('saccos', 'saccos.id', '=', 'vehicles.sacco_id')
            ->whereBetween('summaries.trans_date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw('summaries.mpesa_txn + summaries.cash_txn > 0')
            ->groupBy('vehicles.id', 'saccos.name')
            ->selectRaw('vehicles.id, vehicles.plate, vehicles.brand, vehicles.status, vehicles.sacco_id, saccos.name AS sacco_name')
            ->selectRaw('vehicles.merchant_short_code, vehicles.till_number')
            ->selectRaw('SUM(summaries.mpesa_amount) AS mpesa_kes, SUM(summaries.cash_amount) AS cash_kes')
            ->selectRaw('SUM(summaries.mpesa_txn + summaries.cash_txn) AS payments')
            ->selectRaw('COUNT(*) AS active_days, MAX(summaries.trans_date) AS last_active_date')
            ->get()
            ->map(function (object $r): array {
                $kes = (float) $r->mpesa_kes + (float) $r->cash_kes;

                return [
                    'vehicle_id' => (int) $r->id,
                    'plate' => $r->plate,
                    'brand' => $r->brand,
                    'status' => (bool) $r->status,
                    'sacco' => $r->sacco_id === null ? null : ['id' => (int) $r->sacco_id, 'name' => $r->sacco_name],
                    'merchant_short_code' => $r->merchant_short_code,
                    'till_number' => $r->till_number,
                    'payments' => (int) $r->payments,
                    'kes' => round($kes, 2),
                    'mpesa_kes' => round((float) $r->mpesa_kes, 2),
                    'cash_kes' => round((float) $r->cash_kes, 2),
                    'active_days' => (int) $r->active_days,
                    'kes_per_day' => round($kes / max(1, (int) $r->active_days), 2),
                    'last_active_date' => substr((string) $r->last_active_date, 0, 10),
                ];
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filtered(Collection $rows, array $validated): Collection
    {
        $exclude = array_map('intval', $validated['exclude_sacco_id'] ?? []);
        $q = mb_strtolower(trim((string) ($validated['q'] ?? '')));

        return $rows
            ->reject(fn (array $r): bool => $r['sacco'] !== null && in_array($r['sacco']['id'], $exclude, true))
            ->when(isset($validated['sacco_id']), fn (Collection $c) => $c->filter(fn (array $r): bool => ($r['sacco']['id'] ?? null) === (int) $validated['sacco_id']))
            ->when(isset($validated['brand']), fn (Collection $c) => $c->where('brand', $validated['brand']))
            ->when($q !== '', fn (Collection $c) => $c->filter(fn (array $r): bool => str_contains(mb_strtolower((string) $r['plate']), $q)
                || str_contains(mb_strtolower((string) ($r['sacco']['name'] ?? '')), $q)))
            ->values();
    }
}
