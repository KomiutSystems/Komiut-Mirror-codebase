<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\School;

use App\Http\Controllers\APIs\School\Concerns\ResolvesCallerSchool;
use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\Location\VehicleLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group School
 *
 * A school's buses and which of them are on the road right now, for the
 * school's own staff (and the superadmin). See docs/school-bus-tracking.md.
 */
class SchoolBusesController extends Controller
{
    use ResolvesCallerSchool;

    /**
     * More buses than any school runs. The list has no pages (the contract is
     * one array), so this is the bound, not a page size.
     */
    private const MAX_BUSES = 500;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * School buses
     *
     * A school admin gets every bus in their school. A superadmin gets every
     * bus in EVERY school, grouped by school (school name, then plate), with a
     * `schools` summary for the overview and pages of 50 -- or one school with
     * `?school=`. Each bus carries whether it is broadcasting right now (a
     * fresh position inside VehicleLocationService::FRESH_SECONDS).
     *
     * @authenticated
     *
     * @queryParam school integer Superadmin only: just this school. Example: 12
     * @queryParam page integer Superadmin, all schools: page number (50 a page). Example: 1
     *
     * @response 200 scenario="school admin" {"school":{"id":12,"name":"Greenfield Academy"},"buses":[{"id":717,"plate":"KCW 563E","fleet_no":"3","school":{"id":12,"name":"Greenfield Academy"},"live":true}]}
     * @response 200 scenario="superadmin, all schools" {"schools":[{"id":12,"name":"Greenfield Academy","buses":2,"live":1}],"buses":[{"id":717,"plate":"KCW 563E","fleet_no":"3","school":{"id":12,"name":"Greenfield Academy"},"live":true}],"total":2,"page":1,"per_page":50,"last_page":1}
     * @response 403 scenario="caller's SACCO is not a school" {"error":"This is for school accounts."}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin() && ! $request->filled('school')) {
            return $this->everySchool($request);
        }

        $school = $this->callerSchool($request);
        if ($school === null) {
            return $this->notASchool();
        }

        $buses = $this->busRows()
            ->where('vehicles.sacco_id', $school->id)
            ->orderBy('vehicles.plate')
            ->limit(self::MAX_BUSES)
            ->get()
            ->map(fn (Vehicle $bus) => $this->busRow($bus))
            ->values();

        return response()->json(['school' => ['id' => (int) $school->id, 'name' => $school->name], 'buses' => $buses]);
    }

    /** The superadmin overview: every school, its numbers, and its buses a page at a time. */
    private function everySchool(Request $request): JsonResponse
    {
        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));

        $schools = Sacco::withoutGlobalScopes()
            ->where('kind', Sacco::KIND_SCHOOL)
            ->orderBy('name')
            ->get(['id', 'name']);
        $schoolIds = $schools->pluck('id')->map(fn ($id) => (int) $id)->all();

        $rows = $this->busRows()
            ->whereIn('vehicles.sacco_id', $schoolIds === [] ? [0] : $schoolIds)
            ->join('saccos as school', 'school.id', '=', 'vehicles.sacco_id')
            ->orderBy('school.name')
            ->orderBy('vehicles.plate')
            ->get();

        // Summaries from the same rows: one query for the whole overview.
        $bySchool = $rows->groupBy('sacco_id');
        $summary = $schools->map(fn (Sacco $school) => [
            'id' => (int) $school->id,
            'name' => $school->name,
            'buses' => $bySchool->get($school->id)?->count() ?? 0,
            'live' => $bySchool->get($school->id)?->filter(fn ($bus) => (int) $bus->live_count > 0)->count() ?? 0,
        ])->values();

        return response()->json([
            'schools' => $summary,
            'buses' => $rows->forPage($page, $perPage)->map(fn (Vehicle $bus) => $this->busRow($bus))->values(),
            'total' => $rows->count(),
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
        ]);
    }

    /**
     * The bus columns a list needs, with the live flag as a correlated
     * subquery on vehicle_locations.vehicle_id (unique) -- never a query per
     * bus.
     */
    private function busRows()
    {
        $fresh = now()->subSeconds(VehicleLocationService::FRESH_SECONDS);

        return Vehicle::query()
            ->with(['sacco' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'name'])])
            ->select(['vehicles.id', 'vehicles.plate', 'vehicles.fleet_no', 'vehicles.sacco_id'])
            ->selectSub(
                VehicleLocation::withoutGlobalScopes()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('vehicle_locations.vehicle_id', 'vehicles.id')
                    ->where('vehicle_locations.broadcasting', true)
                    ->where('vehicle_locations.recorded_at', '>=', $fresh)
                    ->toBase(),
                'live_count',
            );
    }

    /** @return array<string, mixed> */
    private function busRow(Vehicle $bus): array
    {
        return [
            'id' => (int) $bus->id,
            'plate' => $bus->plate,
            'fleet_no' => $bus->fleet_no,
            'school' => $bus->sacco === null ? null : ['id' => (int) $bus->sacco->id, 'name' => $bus->sacco->name],
            'live' => (int) $bus->live_count > 0,
        ];
    }
}
