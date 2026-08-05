<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Vehicles;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin fleet read: the platform's cross-brand vehicle roster.
 *
 * till_conflict reuses the exact duplicate definition from
 * App\Observers\Super\Money\VehiclePaymentObserver::checkDuplicate — a
 * till_number OR merchant_short_code shared by more than one vehicle, scanned
 * cross-brand (withoutGlobalScopes) and never comparing the two fields against
 * each other. Computed set-wise (groupBy/having) rather than per row, so the
 * list stays O(1) extra queries regardless of page size.
 */
final class VehiclesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::query()->with(['sacco:id,name', 'seat:id,name,seats']);

        $query->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')));
        $query->when($request->filled('sacco_id'), fn ($q) => $q->where('sacco_id', $request->input('sacco_id')));
        $query->when($request->has('status'), fn ($q) => $q->where('status', $request->boolean('status')));

        $query->when($request->filled('q'), function ($q) use ($request): void {
            $term = '%'.$request->input('q').'%';
            $q->where(function ($qq) use ($term): void {
                $qq->where('plate', 'LIKE', $term)
                    ->orWhere('fleet_no', 'LIKE', $term)
                    ->orWhere('till_number', 'LIKE', $term)
                    ->orWhere('merchant_short_code', 'LIKE', $term);
            });
        });

        if ($request->has('till_conflict')) {
            $wantConflict = $request->boolean('till_conflict');
            $conflictTill = $this->duplicateValues('till_number');
            $conflictMerchant = $this->duplicateValues('merchant_short_code');

            if ($wantConflict) {
                $query->where(function ($q) use ($conflictTill, $conflictMerchant): void {
                    $q->whereIn('till_number', $conflictTill)
                        ->orWhereIn('merchant_short_code', $conflictMerchant);
                });
            } else {
                $query->where(function ($q) use ($conflictTill): void {
                    $q->whereNull('till_number')->orWhereNotIn('till_number', $conflictTill);
                })->where(function ($q) use ($conflictMerchant): void {
                    $q->whereNull('merchant_short_code')->orWhereNotIn('merchant_short_code', $conflictMerchant);
                });
            }
        }

        // Cheap best-effort "last trip": a correlated subselect against the
        // queues table directly (bypasses Eloquent scopes entirely, so it does
        // not depend on the requester being exempt from them).
        $query->addSelect(['last_trip_at' => DB::table('queues')
            ->selectRaw('MAX(start_time)')
            ->whereColumn('queues.vehicle_id', 'vehicles.id'),
        ]);

        $perPage = max(1, min((int) $request->input('per_page', 25), 100));
        $paginator = $query->orderByDesc('id')->paginate($perPage)->appends($request->query());

        $ids = collect($paginator->items())->pluck('id')->all();

        // Current open driver assignment per vehicle, bounded to this page —
        // mirrors App\Services\Driver\VehicleAssignment's "current assignment"
        // shape (status=true, end_date null, user type=Driver), read-only.
        $drivers = VehicleUser::withoutGlobalScopes()
            ->whereIn('vehicle_id', $ids)
            ->where('status', true)
            ->whereNull('end_date')
            ->whereHas('user', fn ($q) => $q->where('type', UserType::Driver))
            ->with('user:id,firstname,lastname')
            ->get(['id', 'vehicle_id', 'user_id'])
            ->keyBy('vehicle_id');

        // Conflict membership restricted to the values actually on this page —
        // bounded, no N+1.
        $pageTills = collect($paginator->items())->pluck('till_number')->filter()->unique()->values();
        $pageMerchants = collect($paginator->items())->pluck('merchant_short_code')->filter()->unique()->values();
        $conflictTillOnPage = $pageTills->isEmpty() ? collect() : $this->duplicateValues('till_number', $pageTills->all());
        $conflictMerchantOnPage = $pageMerchants->isEmpty() ? collect() : $this->duplicateValues('merchant_short_code', $pageMerchants->all());

        return SlimPage::of($paginator, function (Vehicle $vehicle) use ($drivers, $conflictTillOnPage, $conflictMerchantOnPage): array {
            $driverAssignment = $drivers->get($vehicle->id);
            $driverUser = $driverAssignment?->user;

            $tillConflict = ($vehicle->till_number !== null && $conflictTillOnPage->contains($vehicle->till_number))
                || ($vehicle->merchant_short_code !== null && $conflictMerchantOnPage->contains($vehicle->merchant_short_code));

            return [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'fleet_no' => $vehicle->fleet_no,
                'brand' => $vehicle->brand,
                'sacco' => $vehicle->sacco !== null ? ['id' => $vehicle->sacco->id, 'name' => $vehicle->sacco->name] : null,
                'seat' => $vehicle->seat !== null ? ['name' => $vehicle->seat->name, 'seats' => $vehicle->seat->seats] : null,
                'till_number' => $vehicle->till_number,
                'merchant_short_code' => $vehicle->merchant_short_code,
                'till_conflict' => $tillConflict,
                'driver' => $driverUser !== null ? [
                    'id' => $driverUser->id,
                    'name' => trim($driverUser->firstname.' '.$driverUser->lastname),
                ] : null,
                'status' => (bool) $vehicle->status,
                'last_trip_at' => $vehicle->getAttribute('last_trip_at'),
            ];
        })->response();
    }

    /**
     * The set of values for $column shared by more than one vehicle, scanned
     * cross-brand — the same query shape as VehiclePaymentObserver::checkDuplicate,
     * generalised from "does this one value have a duplicate" to "which values
     * in this set are duplicated".
     *
     * @param  array<int,string>|null  $restrictTo  when given, only consider these values (keeps the query bounded to a page)
     * @return Collection<int,string>
     */
    private function duplicateValues(string $column, ?array $restrictTo = null): Collection
    {
        $query = Vehicle::withoutGlobalScopes()
            ->whereNotNull($column)
            ->where($column, '!=', '');

        if ($restrictTo !== null) {
            $query->whereIn($column, $restrictTo);
        }

        return $query->select($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);
    }
}
