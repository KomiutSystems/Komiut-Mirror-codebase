<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\Mpesa;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin console — cross-brand PAYMENT read aggregates (live/aggregate
 * data; NOT the money audit trail — see MoneyLogController for that).
 *
 * Payment "state" spans two disjoint pipelines in this codebase:
 *   - the C2B till pipeline: Mpesa (raw Daraja confirmation) <-> Transaction
 *     (the tenant ledger row a vehicle/booking gets credited against);
 *   - the STK-push pipeline: MpesaStkCallback (per-push local state, keyed off
 *     a Booking) which never touches Transaction/Mpesa at all.
 *
 * Sourcing precision per status (see README note in the class doc / PR notes):
 *   settled       — PRECISE. Transaction (mpesa_id set) joined to Mpesa/Vehicle/
 *                   Sacco. This is the base query.
 *   unreconciled  — PRECISE for existence (a Mpesa row with no Transaction is
 *                   exactly what PaymentReconciliationAlerter flags), but the
 *                   row carries NO brand/sacco/vehicle — that's the point of it
 *                   being unreconciled. A brand/sacco/vehicle filter therefore
 *                   can never match and returns empty rather than guessing.
 *   pending/failed — APPROXIMATE, sourced from MpesaStkCallback + Booking (the
 *                   only pipeline that carries pending/failed local state).
 *                   pending = no callback processed yet and not cancelled.
 *                   failed  = the passenger cancelled OR a callback was
 *                   processed but the booking never got marked paid (mirrors
 *                   StkStatusController's own status vocabulary). QR-code-only
 *                   pushes (no booking_id) are not attributable to a
 *                   vehicle/sacco/brand this cheaply and are excluded.
 */
final class PaymentsController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $validated = $request->validate([
            'status' => 'nullable|in:settled,pending,failed,unreconciled',
            'brand' => 'nullable|string',
            'sacco_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|integer',
            'q' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer',
        ]);

        $status = $validated['status'] ?? 'settled';
        $perPage = max(1, min((int) ($validated['per_page'] ?? 25), 100));

        [$paginator, $mapper] = match ($status) {
            'unreconciled' => [$this->unreconciledQuery($request)->paginate($perPage), fn ($m) => $this->presentUnreconciled($m)],
            'pending' => [$this->stkQuery($request, pending: true)->paginate($perPage), fn ($r) => $this->presentStk($r, pending: true)],
            'failed' => [$this->stkQuery($request, pending: false)->paginate($perPage), fn ($r) => $this->presentStk($r, pending: false)],
            default => [$this->settledQuery($request)->paginate($perPage), fn ($t) => $this->presentSettled($t)],
        };

        return SlimPage::of($paginator, $mapper);
    }

    /**
     * Single-object aggregate for the header tiles + volume chart. Defaults to
     * the trailing 30 days. `unreconciled`/`unreconciled_value` are computed
     * GLOBALLY (not brand/sacco filtered) — Mpesa rows carry no such
     * attribution by definition, so filtering them would just silently return
     * zero rather than a real answer.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand' => 'nullable|string',
            'sacco_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        // Default range is a full 30-day window ending NOW, not midnight — a
        // date-only default `to` would silently exclude the rest of today.
        $to = $validated['to'] ?? now();
        $from = $validated['from'] ?? now()->subDays(30);

        $base = Transaction::query()
            ->whereNotNull('mpesa_id')
            ->when($request->filled('brand'), fn ($q) => $q->whereHas('vehicle', fn ($vq) => $vq->where('brand', $request->input('brand'))))
            ->when($request->filled('sacco_id'), fn ($q) => $q->whereHas('vehicle', fn ($vq) => $vq->where('sacco_id', $request->input('sacco_id'))))
            ->whereBetween('trans_date', [$from, $to]);

        $grossVolume = (float) (clone $base)->sum('amount');
        $settledCount = (int) (clone $base)->count();

        $failedQuery = DB::table('mpesa_stk_callbacks as c')
            ->join('bookings as b', 'b.id', '=', 'c.booking_id')
            ->leftJoin('queues as qu', 'qu.id', '=', 'b.queue_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'qu.vehicle_id')
            ->whereNotNull('c.booking_id')
            ->where(function ($qq): void {
                $qq->whereNotNull('c.cancelled_at')
                    ->orWhere(function ($w): void {
                        $w->whereNotNull('c.processed_at')->where('b.paid', false);
                    });
            })
            ->whereBetween('c.created_at', [$from, $to]);
        if ($request->filled('brand')) {
            $failedQuery->where('v.brand', $request->input('brand'));
        }
        if ($request->filled('sacco_id')) {
            $failedQuery->where('v.sacco_id', $request->input('sacco_id'));
        }
        $failedCount = (int) $failedQuery->count();

        $unreconciledBase = Mpesa::query()->whereDoesntHave('transaction')->whereBetween('TransTime', [$from, $to]);
        $unreconciledCount = (int) (clone $unreconciledBase)->count();
        $unreconciledValue = (float) (clone $unreconciledBase)
            ->selectRaw('SUM(CAST(TransAmount AS DECIMAL(15,2))) as total')
            ->value('total');

        $denominator = $settledCount + $failedCount;
        $successRate = $denominator > 0 ? round(($settledCount / $denominator) * 100, 2) : 0.0;

        $dateExpr = $this->dateExpr('trans_date');
        $seriesRows = (clone $base)
            ->selectRaw("{$dateExpr} as d, SUM(amount) as total")
            ->groupBy(DB::raw($dateExpr))
            ->orderBy(DB::raw($dateExpr))
            ->get();
        $series = $seriesRows->map(fn ($r) => [$r->d, (float) $r->total])->values()->all();

        return response()->json([
            'gross_volume' => round($grossVolume, 2),
            'currency' => 'KES',
            'settled' => $settledCount,
            'failed' => $failedCount,
            'unreconciled' => $unreconciledCount,
            'unreconciled_value' => round($unreconciledValue, 2),
            'success_rate' => $successRate,
            'series' => $series,
        ]);
    }

    private function settledQuery(Request $request)
    {
        return Transaction::query()
            ->whereNotNull('mpesa_id')
            ->with(['mpesa', 'vehicle.sacco'])
            ->when($request->filled('brand'), fn ($q) => $q->whereHas('vehicle', fn ($vq) => $vq->where('brand', $request->input('brand'))))
            ->when($request->filled('sacco_id'), fn ($q) => $q->whereHas('vehicle', fn ($vq) => $vq->where('sacco_id', $request->input('sacco_id'))))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->input('vehicle_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('trans_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('trans_date', '<=', $request->input('to')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(function ($qq) use ($term): void {
                    $qq->whereHas('mpesa', function ($mq) use ($term): void {
                        $mq->where('TransID', 'like', $term)
                            ->orWhere('FirstName', 'like', $term)
                            ->orWhere('LastName', 'like', $term)
                            ->orWhere('MSISDN', 'like', $term);
                    })->orWhereHas('vehicle', fn ($vq) => $vq->where('plate', 'like', $term));
                });
            })
            ->orderByDesc('trans_date');
    }

    private function unreconciledQuery(Request $request)
    {
        $query = Mpesa::query()->whereDoesntHave('transaction');

        // Mpesa carries no vehicle/brand/sacco attribution — that is exactly
        // what makes a row unreconciled. A brand/sacco/vehicle filter can never
        // legitimately match one of these rows, so short-circuit to empty
        // instead of silently ignoring the filter.
        if ($request->filled('brand') || $request->filled('sacco_id') || $request->filled('vehicle_id')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->when($request->filled('from'), fn ($q) => $q->where('TransTime', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('TransTime', '<=', $request->input('to')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->input('q').'%';
                $q->where(function ($qq) use ($term): void {
                    $qq->where('TransID', 'like', $term)
                        ->orWhere('FirstName', 'like', $term)
                        ->orWhere('LastName', 'like', $term)
                        ->orWhere('MSISDN', 'like', $term)
                        ->orWhere('BusinessShortCode', 'like', $term);
                });
            })
            ->orderByDesc('TransTime');
    }

    /**
     * pending/failed share the same source: MpesaStkCallback rows tied to a
     * Booking (QR-only pushes have no booking and are excluded — no cheap
     * vehicle/brand attribution for those). Built as a flat join instead of
     * Eloquent relations because MpesaStkCallback declares no booking() relation.
     */
    private function stkQuery(Request $request, bool $pending)
    {
        $query = DB::table('mpesa_stk_callbacks as c')
            ->join('bookings as b', 'b.id', '=', 'c.booking_id')
            ->leftJoin('queues as qu', 'qu.id', '=', 'b.queue_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'qu.vehicle_id')
            ->leftJoin('saccos as s', 's.id', '=', 'v.sacco_id')
            ->select([
                'c.id as id', 'c.created_at as occurred_at', 'c.processed_at as processed_at', 'c.cancelled_at as cancelled_at',
                'b.name as passenger_name', 'b.phone as passenger_phone', 'b.amount as amount',
                'v.id as vehicle_id', 'v.plate as vehicle_plate', 'v.brand as brand', 'v.till_number as till_number',
                's.id as sacco_id', 's.name as sacco_name',
            ])
            ->whereNotNull('c.booking_id');

        if ($pending) {
            $query->whereNull('c.processed_at')->whereNull('c.cancelled_at');
        } else {
            $query->where(function ($qq): void {
                $qq->whereNotNull('c.cancelled_at')
                    ->orWhere(function ($w): void {
                        $w->whereNotNull('c.processed_at')->where('b.paid', false);
                    });
            });
        }

        if ($request->filled('brand')) {
            $query->where('v.brand', $request->input('brand'));
        }
        if ($request->filled('sacco_id')) {
            $query->where('v.sacco_id', $request->input('sacco_id'));
        }
        if ($request->filled('vehicle_id')) {
            $query->where('v.id', $request->input('vehicle_id'));
        }
        if ($request->filled('from')) {
            $query->where('c.created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('c.created_at', '<=', $request->input('to'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($qq) use ($term): void {
                $qq->where('b.name', 'like', $term)
                    ->orWhere('b.phone', 'like', $term)
                    ->orWhere('v.plate', 'like', $term);
            });
        }

        return $query->orderByDesc('c.created_at');
    }

    /** @return array<string,mixed> */
    private function presentSettled(Transaction $t): array
    {
        $mpesa = $t->mpesa;
        $vehicle = $t->vehicle;
        $sacco = $vehicle?->sacco;

        return [
            'id' => $mpesa?->TransID,
            'occurred_at' => $mpesa?->TransTime !== null ? Carbon::parse($mpesa->TransTime)->toIso8601String() : null,
            'brand' => $vehicle?->brand,
            'amount' => (float) $t->amount,
            'currency' => 'KES',
            'method' => 'mpesa',
            'status' => 'settled',
            'mpesa_receipt' => $mpesa?->TransID,
            'passenger' => [
                'id' => null,
                'name' => $this->fullName($mpesa?->FirstName, $mpesa?->LastName),
                'phone' => $this->maskPhone($mpesa?->MSISDN),
            ],
            'sacco' => $sacco !== null ? ['id' => $sacco->id, 'name' => $sacco->name] : null,
            'vehicle' => $vehicle !== null ? ['id' => $vehicle->id, 'plate' => $vehicle->plate] : null,
            'till_number' => $mpesa?->BusinessShortCode,
            'failure_reason' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function presentUnreconciled(Mpesa $m): array
    {
        return [
            'id' => $m->TransID,
            'occurred_at' => $m->TransTime !== null ? Carbon::parse($m->TransTime)->toIso8601String() : null,
            'brand' => null,
            'amount' => (float) $m->TransAmount,
            'currency' => 'KES',
            'method' => 'mpesa',
            'status' => 'unreconciled',
            'mpesa_receipt' => $m->TransID,
            'passenger' => [
                'id' => null,
                'name' => $this->fullName($m->FirstName, $m->LastName),
                'phone' => $this->maskPhone($m->MSISDN),
            ],
            'sacco' => null,
            'vehicle' => null,
            'till_number' => $m->BusinessShortCode,
            'failure_reason' => 'No matching transaction/vehicle — unattributed payment.',
        ];
    }

    /** @return array<string,mixed> */
    private function presentStk(object $row, bool $pending): array
    {
        return [
            'id' => 'STK-'.$row->id,
            'occurred_at' => $row->occurred_at !== null ? Carbon::parse($row->occurred_at)->toIso8601String() : null,
            'brand' => $row->brand,
            'amount' => $row->amount !== null ? (float) $row->amount : 0.0,
            'currency' => 'KES',
            'method' => 'mpesa',
            'status' => $pending ? 'pending' : 'failed',
            'mpesa_receipt' => null,
            'passenger' => [
                'id' => null,
                'name' => $row->passenger_name,
                'phone' => $this->maskPhone($row->passenger_phone),
            ],
            'sacco' => $row->sacco_id !== null ? ['id' => (int) $row->sacco_id, 'name' => $row->sacco_name] : null,
            'vehicle' => $row->vehicle_id !== null ? ['id' => (int) $row->vehicle_id, 'plate' => $row->vehicle_plate] : null,
            'till_number' => $row->till_number,
            'failure_reason' => $pending
                ? null
                : ($row->cancelled_at !== null ? 'Passenger cancelled the push.' : 'Callback received but payment did not settle.'),
        ];
    }

    private function fullName(?string $first, ?string $last): ?string
    {
        $name = trim(($first ?? '').' '.($last ?? ''));

        return $name === '' ? null : $name;
    }

    /** Mask a phone number, keeping only the first 4 and last 4 characters visible. */
    private function maskPhone(?string $msisdn): ?string
    {
        if ($msisdn === null || $msisdn === '') {
            return null;
        }

        $len = strlen($msisdn);
        if ($len <= 8) {
            return $len <= 4 ? $msisdn : str_repeat('*', $len - 4).substr($msisdn, -4);
        }

        return substr($msisdn, 0, 4).str_repeat('*', $len - 8).substr($msisdn, -4);
    }

    /** Driver-portable full-date (YYYY-MM-DD) grouping expression. */
    private function dateExpr(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            default => "DATE({$column})",
        };
    }
}
