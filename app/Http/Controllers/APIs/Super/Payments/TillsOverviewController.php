<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\AuditLog;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Super-admin console — cross-brand tills overview: vehicles grouped by
 * till_number (the same duplicate-till definition VehiclePaymentObserver uses)
 * plus a straight projection of the vehicle.payment_details.changed audit trail
 * that observer writes.
 */
final class TillsOverviewController extends Controller
{
    /**
     * One row per till_number, cross-brand. `is_conflict` is computed over the
     * FULL unfiltered grouping (a till shared across brands must still show as
     * a conflict even when a brand filter is applied) — display filters are
     * applied only after that.
     */
    public function index(Request $request): JsonResource
    {
        $validated = $request->validate([
            'brand' => 'nullable|string',
            'sacco_id' => 'nullable|integer',
            'q' => 'nullable|string',
            'conflict' => 'nullable|boolean',
            'per_page' => 'nullable|integer',
        ]);

        $groups = Vehicle::query()
            ->whereNotNull('till_number')
            ->where('till_number', '!=', '')
            ->with('sacco:id,name')
            ->get(['id', 'plate', 'till_number', 'merchant_short_code', 'sacco_id', 'brand'])
            ->groupBy('till_number');

        $rows = $groups->map(function ($group, $tillNumber): array {
            $first = $group->first();

            return [
                'till_number' => (string) $tillNumber,
                'merchant_short_code' => $first->merchant_short_code,
                'brand' => $first->brand,
                'vehicles' => $group->map(fn (Vehicle $v) => [
                    'id' => $v->id,
                    'plate' => $v->plate,
                    'sacco' => $v->sacco?->name,
                ])->values()->all(),
                'is_conflict' => $group->count() > 1,
                '_vehicle_ids' => $group->pluck('id')->all(),
                '_sacco_ids' => $group->pluck('sacco_id')->filter()->unique()->values()->all(),
                '_search' => $tillNumber.' '.$group->pluck('plate')->implode(' '),
            ];
        })->values();

        if ($request->filled('brand')) {
            $brand = $request->input('brand');
            $rows = $rows->filter(fn (array $r) => $r['brand'] === $brand)->values();
        }
        if ($request->filled('sacco_id')) {
            $saccoId = (int) $request->input('sacco_id');
            $rows = $rows->filter(fn (array $r) => in_array($saccoId, $r['_sacco_ids'], true))->values();
        }
        if ($request->filled('q')) {
            $term = mb_strtolower($request->input('q'));
            $rows = $rows->filter(fn (array $r) => str_contains(mb_strtolower($r['_search']), $term))->values();
        }
        if ($request->has('conflict')) {
            $wantConflict = $request->boolean('conflict');
            $rows = $rows->filter(fn (array $r) => $r['is_conflict'] === $wantConflict)->values();
        }

        $perPage = max(1, min((int) ($validated['per_page'] ?? 25), 100));
        $page = max(1, (int) $request->input('page', 1));
        $total = $rows->count();

        // volume_30d is only computed for the page actually being returned —
        // one query per visible till, not per till in the whole system.
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values()->map(function (array $r): array {
            $volume = Transaction::whereIn('vehicle_id', $r['_vehicle_ids'])
                ->where('trans_date', '>=', now()->subDays(30))
                ->sum('amount');

            unset($r['_vehicle_ids'], $r['_sacco_ids'], $r['_search']);
            $r['volume_30d'] = (float) $volume;

            return $r;
        });

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page);

        return SlimPage::of($paginator);
    }

    /**
     * Projects the audit_logs rows VehiclePaymentObserver / MpesaPaymentSettingObserver
     * write for action=vehicle.payment_details.changed. No recomputation — the
     * observer already stores field/from/to in `data`; a SACCO-credential change
     * (subject_type=mpesa_payment_setting) has no vehicle, so `vehicle` is null
     * for those rows.
     */
    public function changes(Request $request): JsonResource
    {
        $validated = $request->validate([
            'brand' => 'nullable|string',
            'vehicle_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer',
        ]);

        $perPage = max(1, min((int) ($validated['per_page'] ?? 25), 100));

        $query = AuditLog::query()
            ->where('action', 'vehicle.payment_details.changed')
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when(
                $request->filled('vehicle_id'),
                fn ($q) => $q->where('subject_type', 'vehicle')->where('subject_id', (string) $request->input('vehicle_id'))
            )
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->input('to')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $paginator = $query->paginate($perPage);

        // Batch-resolve sacco names for the page — data carries saccoId, not name.
        $saccoIds = collect($paginator->items())
            ->map(fn (AuditLog $log) => $log->data['saccoId'] ?? null)
            ->filter()
            ->unique()
            ->values();
        $saccoNames = $saccoIds->isEmpty()
            ? collect()
            : Sacco::query()->whereIn('id', $saccoIds)->pluck('name', 'id');

        return SlimPage::of($paginator, function (AuditLog $log) use ($saccoNames): array {
            $data = $log->data ?? [];
            $saccoId = $data['saccoId'] ?? null;
            $vehicleId = $data['vehicleId'] ?? null;

            return [
                'id' => $log->id,
                'occurred_at' => optional($log->created_at)->toIso8601String(),
                'brand' => $log->brand,
                'vehicle' => $vehicleId !== null
                    ? ['id' => (int) $vehicleId, 'plate' => $data['plate'] ?? null]
                    : null,
                'sacco' => $saccoId !== null
                    ? ['id' => (int) $saccoId, 'name' => $saccoNames->get((int) $saccoId)]
                    : null,
                'field' => $data['field'] ?? null,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
                'actor' => [
                    'type' => $log->actor_type,
                    'id' => $log->actor_id,
                    'label' => $log->actor_label,
                    'ip' => $log->ip,
                ],
                'audit_id' => $log->id,
            ];
        });
    }
}
