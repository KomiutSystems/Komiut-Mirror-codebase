<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\School;

use App\Http\Controllers\APIs\School\Concerns\ResolvesCallerSchool;
use App\Http\Controllers\Controller;
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
     * My school's buses
     *
     * Every bus in the caller's school with its follow code, how many parents
     * follow it, and whether it is broadcasting right now (a fresh position
     * inside VehicleLocationService::FRESH_SECONDS). A bus that has no code
     * yet -- one added before its SACCO became a school, or by a path that
     * does not issue one -- is given one here, so the school always has a code
     * to hand out.
     *
     * @authenticated
     *
     * @queryParam school integer Superadmin only: the school whose buses to list. Anyone else always gets their own school. Example: 12
     *
     * @response 200 {"buses":[{"id":717,"plate":"KCW 563E","fleet_no":"3","follow_code":"AB3K7PQX","followers":14,"live":true}]}
     * @response 403 scenario="caller's SACCO is not a school" {"error":"This is for school accounts."}
     */
    public function index(Request $request): JsonResponse
    {
        $school = $this->callerSchool($request);
        if ($school === null) {
            return $this->notASchool();
        }

        $this->issueMissingCodes((int) $school->id);

        $fresh = now()->subSeconds(VehicleLocationService::FRESH_SECONDS);

        // One query for the page: the follower count and the live flag are
        // correlated subqueries on indexed columns (vehicle_followers.vehicle_id,
        // vehicle_locations.vehicle_id is unique), never a query per bus.
        $buses = Vehicle::query()
            ->where('vehicles.sacco_id', $school->id)
            ->select(['vehicles.id', 'vehicles.plate', 'vehicles.fleet_no', 'vehicles.follow_code'])
            ->withCount('followers')
            ->selectSub(
                VehicleLocation::withoutGlobalScopes()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('vehicle_locations.vehicle_id', 'vehicles.id')
                    ->where('vehicle_locations.broadcasting', true)
                    ->where('vehicle_locations.recorded_at', '>=', $fresh)
                    ->toBase(),
                'live_count',
            )
            ->orderBy('vehicles.plate')
            ->limit(self::MAX_BUSES)
            ->get()
            ->map(fn (Vehicle $bus) => [
                'id' => (int) $bus->id,
                'plate' => $bus->plate,
                'fleet_no' => $bus->fleet_no,
                'follow_code' => $bus->follow_code,
                'followers' => (int) $bus->followers_count,
                'live' => (int) $bus->live_count > 0,
            ])
            ->values();

        return response()->json(['buses' => $buses]);
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
    private function issueMissingCodes(int $schoolId): void
    {
        $missing = Vehicle::withoutGlobalScopes()
            ->where('sacco_id', $schoolId)
            ->whereNull('follow_code')
            ->limit(self::MAX_BUSES)
            ->pluck('id');

        foreach ($missing as $id) {
            FollowCode::issueIfMissing((int) $id);
        }
    }
}
