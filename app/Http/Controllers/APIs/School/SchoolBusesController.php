<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\School;

use App\Http\Controllers\APIs\School\Concerns\ResolvesCallerSchool;
use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Models\VehicleLocation;
use App\Services\Location\VehicleLocationService;
use App\Services\Platform\AuditLogger;
use App\Services\School\FollowCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @group School
 *
 * A school's buses and the follow code it hands parents for each. The only
 * place a follow code ever leaves the API (Vehicle hides it from every other
 * serialization). See docs/school-bus-tracking.md.
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
     * `?school=`. Each bus carries its follow code, how many parents follow it,
     * and whether it is broadcasting right now (a fresh position inside
     * VehicleLocationService::FRESH_SECONDS). A bus with no code yet -- one
     * added by a path that does not issue one -- is given one here, so a
     * school always has a code to hand out.
     *
     * @authenticated
     *
     * @queryParam school integer Superadmin only: just this school. Example: 12
     * @queryParam page integer Superadmin, all schools: page number (50 a page). Example: 1
     *
     * @response 200 scenario="school admin" {"school":{"id":12,"name":"Greenfield Academy"},"buses":[{"id":717,"plate":"KCW 563E","fleet_no":"3","school":{"id":12,"name":"Greenfield Academy"},"follow_code":"AB3K7PQX","followers":14,"live":true}]}
     * @response 200 scenario="superadmin, all schools" {"schools":[{"id":12,"name":"Greenfield Academy","buses":2,"live":1,"followers":14}],"buses":[{"id":717,"plate":"KCW 563E","fleet_no":"3","school":{"id":12,"name":"Greenfield Academy"},"follow_code":"AB3K7PQX","followers":14,"live":true}],"total":2,"page":1,"per_page":50,"last_page":1}
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

        $this->issueMissingCodes([(int) $school->id]);

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

        $this->issueMissingCodes($schoolIds);

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
            'followers' => (int) ($bySchool->get($school->id)?->sum('followers_count') ?? 0),
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
     * The bus columns a list needs, with the follower count and the live flag
     * as correlated subqueries on indexed columns (vehicle_followers.vehicle_id;
     * vehicle_locations.vehicle_id is unique) -- never a query per bus.
     */
    private function busRows()
    {
        $fresh = now()->subSeconds(VehicleLocationService::FRESH_SECONDS);

        return Vehicle::query()
            ->with(['sacco' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'name'])])
            ->select(['vehicles.id', 'vehicles.plate', 'vehicles.fleet_no', 'vehicles.follow_code', 'vehicles.sacco_id'])
            ->withCount('followers')
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
            'follow_code' => $bus->follow_code,
            'followers' => (int) $bus->followers_count,
            'live' => (int) $bus->live_count > 0,
        ];
    }

    /**
     * Rotate a bus's follow code
     *
     * Issues a new code; the old one stops working at once for anyone who has
     * not followed yet. Parents already following keep following unless
     * `revoke_followers` is true, which removes every one of them -- the
     * response to a code that leaked to someone it should not have. Audited.
     *
     * @authenticated
     *
     * @urlParam vehicle integer required The bus. Example: 717
     *
     * @bodyParam revoke_followers boolean Also remove every parent following this bus. Default false. Example: true
     *
     * @response 200 {"bus":{"id":717,"plate":"KCW 563E","follow_code":"Q7MZ4HNC"},"revoked":14}
     * @response 400 scenario="validation" {"errors":{"revoke_followers":["The revoke followers field must be true or false."]}}
     * @response 403 scenario="caller's SACCO is not a school" {"error":"This is for school accounts."}
     * @response 404 scenario="not a bus in your school" {"error":"No such bus in your school."}
     */
    public function rotateCode(Request $request, int $vehicle): JsonResponse
    {
        $bus = $this->schoolBus($request, $vehicle);
        if ($bus instanceof JsonResponse) {
            return $bus;
        }

        $validator = Validator::make($request->all(), [
            'revoke_followers' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $revoke = $request->boolean('revoke_followers');

        [$code, $revoked] = DB::transaction(function () use ($bus, $revoke): array {
            $code = FollowCode::issue($bus);
            $revoked = $revoke ? VehicleFollower::where('vehicle_id', $bus->id)->delete() : 0;

            // Never the code itself: the audit log is read by more people
            // than the school's own staff.
            AuditLogger::record(
                'school.follow_code.rotated',
                ['revoke_followers' => $revoke, 'revoked' => $revoked],
                subject: ['type' => 'vehicle', 'id' => (string) $bus->id],
                saccoId: (int) $bus->sacco_id,
            );

            return [$code, $revoked];
        });

        return response()->json([
            'bus' => ['id' => (int) $bus->id, 'plate' => $bus->plate, 'follow_code' => $code],
            'revoked' => $revoked,
        ]);
    }

    /**
     * Give every bus in the school that has no code one -- through
     * FollowCode::issueIfMissing, a conditional write, so two staff opening
     * the list at once are shown the same code.
     */
    /** @param  list<int>  $schoolIds */
    private function issueMissingCodes(array $schoolIds): void
    {
        if ($schoolIds === []) {
            return;
        }

        $missing = Vehicle::withoutGlobalScopes()
            ->whereIn('sacco_id', $schoolIds)
            ->whereNull('follow_code')
            ->limit(self::MAX_BUSES)
            ->pluck('id');

        foreach ($missing as $id) {
            FollowCode::issueIfMissing((int) $id);
        }
    }
}
