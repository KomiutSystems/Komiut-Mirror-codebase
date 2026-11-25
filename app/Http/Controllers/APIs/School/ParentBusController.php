<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\School;

use App\Http\Controllers\Controller;
use App\Http\Resources\School\FollowedBusResource;
use App\Models\VehicleFollower;
use App\Services\Platform\AuditLogger;
use App\Services\School\FollowCode;
use App\Services\School\Parent\FollowedBuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * @group School bus tracking (parent)
 *
 * A parent -- any passenger account, in either app -- finds their child's
 * school bus by plate, follows it with the follow code the school gave them,
 * and then sees it live and is told when it nears their stop.
 *
 * The bus is read across tenants and brands on purpose (the parent's app and
 * the school are unrelated), so what leaves here is spelled out field by
 * field: search says only that a plate belongs to a school; a position is
 * given only for a bus the caller follows, and only while it is live. A wrong
 * code, an unknown bus and a bus that is not a school's all get the same 404,
 * so the follow endpoint cannot be used to learn which ids are school buses.
 * See docs/school-bus-tracking.md.
 */
class ParentBusController extends Controller
{
    /** The one answer to every failed follow; see the class comment. */
    private const NO_MATCH = 'No school bus matches that plate and code.';

    private const NOT_FOLLOWING = 'You are not following this bus.';

    public function __construct(private readonly FollowedBuses $buses)
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Find a school bus by plate
     *
     * Exact plate match, however it is typed ("kcw563e" finds "KCW 563E").
     * School buses only, at most 5. Never a position, route, driver or code:
     * follow the bus with its code to see it.
     *
     * @authenticated
     *
     * @queryParam plate string required The number plate, 3-15 characters. Example: KCW 563E
     *
     * @response 200 {"buses": [{"id": 717, "plate": "KCW 563E", "school": {"id": 2, "name": "Greenfield Academy"}, "following": false}]}
     * @response 422 {"errors": {"plate": ["The plate field must be at least 3 characters."]}}
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), ['plate' => 'required|string|min:3|max:15']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 422);
        }

        $found = $this->buses->searchByPlate((string) $request->query('plate'));

        $following = $found->isEmpty() ? collect() : VehicleFollower::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('vehicle_id', $found->pluck('id')->all())
            ->pluck('vehicle_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        return response()->json(['buses' => $found->map(fn ($bus) => [
            'id' => (int) $bus->id,
            'plate' => $bus->plate,
            'school' => ['id' => (int) $bus->school_id, 'name' => $bus->school_name],
            'following' => $following->has((int) $bus->id),
        ])->values()]);
    }

    /**
     * Buses I follow
     *
     * Each with the parent's stop, where it is right now (only while the
     * driver is broadcasting; `live` is null otherwise), the trip it is on,
     * and the private channel that carries its `vehicle.moved` events.
     *
     * @authenticated
     *
     * @response 200 {"buses": [{"id": 717, "plate": "KCW 563E", "school": {"id": 2, "name": "Greenfield Academy"}, "stop": {"place_id": 12, "name": "Kahawa West", "latitude": -1.18, "longitude": 36.92}, "live": {"latitude": -1.2, "longitude": 36.9, "heading": 74, "recorded_at": "2026-11-26T06:41:12+03:00", "age_seconds": 12}, "trip": {"queue_id": 901, "route": {"id": 5, "name": "Morning run"}}, "channel": "private-school-bus.717", "followed_at": "2026-11-20T18:02:00+03:00"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $followed = $this->buses->forUser((int) $request->user()->id);

        return response()->json(['buses' => FollowedBusResource::collection($followed)->resolve($request)]);
    }

    /**
     * Follow a school bus
     *
     * With the follow code the school gave you. Optionally set your stop,
     * either a stop on the school's route or a pin of your own, not both;
     * you are told when the bus nears it. Following a bus you already follow
     * is fine (200), and updates the stop if one is given.
     *
     * @authenticated
     *
     * @bodyParam code string required The bus's follow code, as the school wrote it. Case and dashes do not matter. Example: AB3K-7PQX
     * @bodyParam stop_place_id integer A stop on the school's route. Example: 12
     * @bodyParam stop_latitude number Your own pickup point; with stop_longitude. Example: -1.18
     * @bodyParam stop_longitude number Your own pickup point; with stop_latitude. Example: 36.92
     *
     * @response 201 {"bus": {"id": 717, "plate": "KCW 563E", "school": {"id": 2, "name": "Greenfield Academy"}, "stop": null, "live": null, "trip": null, "channel": "private-school-bus.717", "followed_at": "2026-11-26T06:41:12+03:00"}}
     * @response 404 {"error": "No school bus matches that plate and code."}
     * @response 422 {"error": "You can follow at most 10 buses. Unfollow one first."}
     * @response 422 {"errors": {"stop_place_id": ["That stop is not on this school's routes."]}}
     * @response 429 {"message": "Too Many Attempts."}
     */
    public function follow(Request $request, string $vehicle): JsonResponse
    {
        $validator = $this->stopValidator($request, ['code' => 'required|string|max:32']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 422);
        }

        // The shape checks above say nothing about any bus; everything from
        // here on does, so nothing past this point may answer differently
        // for a wrong code, an unknown id or a matatu.
        $vehicleId = $this->id($vehicle);
        $bus = $vehicleId === null ? null : $this->buses->schoolBus($vehicleId);
        if ($bus === null || ! FollowCode::matches($bus, (string) $request->input('code'))) {
            return response()->json(['error' => self::NO_MATCH], 404);
        }

        // Only now, with the code proven, may the answer depend on the
        // school's routes.
        $stop = $this->stopAttributes($request, (int) $bus->sacco_id);
        if ($stop instanceof JsonResponse) {
            return $stop;
        }

        $userId = (int) $request->user()->id;

        $result = DB::transaction(function () use ($userId, $bus, $stop) {
            // Serialise one account's follows, so two taps at once cannot
            // both pass the count below and land an eleventh bus.
            DB::table('users')->where('id', $userId)->lockForUpdate()->value('id');

            $existing = VehicleFollower::query()->where('user_id', $userId)->where('vehicle_id', $bus->id)->first();
            if ($existing !== null) {
                if ($stop !== []) {
                    $existing->forceFill($stop)->save();
                }

                return 'kept';
            }

            if (VehicleFollower::query()->where('user_id', $userId)->count() >= VehicleFollower::MAX_PER_USER) {
                return 'full';
            }

            VehicleFollower::query()->create(['user_id' => $userId, 'vehicle_id' => $bus->id] + $stop);

            return 'created';
        });

        if ($result === 'full') {
            return response()->json(['error' => 'You can follow at most '.VehicleFollower::MAX_PER_USER.' buses. Unfollow one first.'], 422);
        }

        if ($result === 'created') {
            // The vehicle, never the code: the audit log is read by people
            // who must not be handed a way in.
            AuditLogger::record(
                'school.bus.followed',
                ['vehicle_id' => (int) $bus->id, 'stop' => $this->stopKind($stop)],
                subject: ['type' => 'vehicle', 'id' => (string) $bus->id],
                saccoId: (int) $bus->sacco_id,
            );
        }

        return $this->busResponse($userId, (int) $bus->id, $result === 'created' ? 201 : 200);
    }

    /**
     * Change my stop on a followed bus
     *
     * A stop on the school's route, or a pin of your own, or `"stop": null`
     * to clear it (no "approaching" alert without one).
     *
     * @authenticated
     *
     * @bodyParam stop_place_id integer A stop on the school's route. Example: 12
     * @bodyParam stop_latitude number Your own pickup point; with stop_longitude. Example: -1.18
     * @bodyParam stop_longitude number Your own pickup point; with stop_latitude. Example: 36.92
     * @bodyParam stop object Send null to clear your stop. Example: null
     *
     * @response 200 {"bus": {"id": 717, "plate": "KCW 563E", "school": {"id": 2, "name": "Greenfield Academy"}, "stop": {"place_id": null, "name": null, "latitude": -1.18, "longitude": 36.92}, "live": null, "trip": null, "channel": "private-school-bus.717", "followed_at": "2026-11-20T18:02:00+03:00"}}
     * @response 404 {"error": "You are not following this bus."}
     * @response 422 {"errors": {"stop": ["Send stop_place_id, or stop_latitude and stop_longitude, or \"stop\": null to clear it."]}}
     */
    public function updateStop(Request $request, string $vehicle): JsonResponse
    {
        $clearing = $request->exists('stop') && $request->input('stop') === null;

        $validator = $this->stopValidator($request);
        $validator->after(function (ValidatorInstance $v) use ($request, $clearing) {
            $given = $request->filled('stop_place_id') || $request->filled('stop_latitude') || $request->filled('stop_longitude');
            if ($request->exists('stop') && ! $clearing) {
                $v->errors()->add('stop', 'Send "stop": null to clear your stop, or the stop fields to set it.');
            } elseif ($clearing && $given) {
                $v->errors()->add('stop', 'Clear the stop or set one, not both.');
            } elseif (! $clearing && ! $given) {
                $v->errors()->add('stop', 'Send stop_place_id, or stop_latitude and stop_longitude, or "stop": null to clear it.');
            }
        });
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 422);
        }

        $userId = (int) $request->user()->id;
        $vehicleId = $this->id($vehicle);
        // The follow, and the bus still being an active school bus: the same
        // gate the list uses, so a bus that dropped off the list cannot be
        // edited either.
        $follower = $vehicleId === null ? null : $this->buses->forUser($userId, $vehicleId)->first();
        if ($follower === null) {
            return response()->json(['error' => self::NOT_FOLLOWING], 404);
        }

        $stop = $clearing ? self::noStop() : $this->stopAttributes($request, (int) $follower->school_id);
        if ($stop instanceof JsonResponse) {
            return $stop;
        }

        VehicleFollower::query()->whereKey($follower->id)->update($stop + ['updated_at' => now()]);

        return $this->busResponse($userId, $vehicleId, 200);
    }

    /**
     * Unfollow a school bus
     *
     * Works whatever has happened to the bus since -- a parent can always
     * take back their own follow.
     *
     * @authenticated
     *
     * @response 204
     * @response 404 {"error": "You are not following this bus."}
     */
    public function unfollow(Request $request, string $vehicle): JsonResponse|Response
    {
        $vehicleId = $this->id($vehicle);
        $follower = $vehicleId === null ? null : VehicleFollower::query()
            ->leftJoin('vehicles', 'vehicles.id', '=', 'vehicle_followers.vehicle_id')
            ->where('vehicle_followers.user_id', $request->user()->id)
            ->where('vehicle_followers.vehicle_id', $vehicleId)
            ->first(['vehicle_followers.id', 'vehicles.sacco_id']);

        if ($follower === null) {
            return response()->json(['error' => self::NOT_FOLLOWING], 404);
        }

        VehicleFollower::query()->whereKey($follower->id)->delete();

        AuditLogger::record(
            'school.bus.unfollowed',
            ['vehicle_id' => $vehicleId],
            subject: ['type' => 'vehicle', 'id' => (string) $vehicleId],
            saccoId: $follower->sacco_id !== null ? (int) $follower->sacco_id : null,
        );

        return response()->noContent();
    }

    /**
     * Shape checks for the stop fields, which depend on no bus. Kenya-ish
     * bounds: a pin outside them is a slipped finger or a spoofed client,
     * never a school run.
     *
     * @param  array<string, string>  $extra
     */
    private function stopValidator(Request $request, array $extra = []): ValidatorInstance
    {
        $validator = Validator::make($request->all(), $extra + [
            'stop_place_id' => 'nullable|integer|min:1',
            'stop_latitude' => 'nullable|numeric|between:-5,5|required_with:stop_longitude',
            'stop_longitude' => 'nullable|numeric|between:33,42|required_with:stop_latitude',
        ]);

        $validator->after(function (ValidatorInstance $v) use ($request) {
            if ($request->filled('stop_place_id') && ($request->filled('stop_latitude') || $request->filled('stop_longitude'))) {
                $v->errors()->add('stop', 'Choose a stop on the route or drop a pin, not both.');
            }
        });

        return $validator;
    }

    /**
     * The stop as columns, or a 422 when the place is not on this school's
     * routes. An empty array when no stop was sent. Changing the stop resets
     * the once-per-run alert marker, so the new stop gets its alert.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function stopAttributes(Request $request, int $schoolId): array|JsonResponse
    {
        if ($request->filled('stop_place_id')) {
            $placeId = (int) $request->input('stop_place_id');
            if (! $this->buses->placeServesSchool($placeId, $schoolId)) {
                return response()->json(['errors' => ['stop_place_id' => ["That stop is not on this school's routes."]]], 422);
            }

            return ['stop_place_id' => $placeId, 'stop_latitude' => null, 'stop_longitude' => null] + self::alertReset();
        }

        if ($request->filled('stop_latitude')) {
            return [
                'stop_place_id' => null,
                'stop_latitude' => round((float) $request->input('stop_latitude'), 7),
                'stop_longitude' => round((float) $request->input('stop_longitude'), 7),
            ] + self::alertReset();
        }

        return [];
    }

    /** @return array<string, null> */
    private static function noStop(): array
    {
        return ['stop_place_id' => null, 'stop_latitude' => null, 'stop_longitude' => null] + self::alertReset();
    }

    /** @return array<string, null> */
    private static function alertReset(): array
    {
        return ['last_alerted_queue_id' => null, 'last_alerted_at' => null];
    }

    /** For the audit row: which kind of stop, never where it is. */
    private function stopKind(array $stop): ?string
    {
        return match (true) {
            ($stop['stop_place_id'] ?? null) !== null => 'place',
            ($stop['stop_latitude'] ?? null) !== null => 'pin',
            default => null,
        };
    }

    private function busResponse(int $userId, int $vehicleId, int $status): JsonResponse
    {
        $follower = $this->buses->forUser($userId, $vehicleId)->first();

        return response()->json(['bus' => $follower === null ? null : (new FollowedBusResource($follower))->resolve()], $status);
    }

    /**
     * The route's {vehicle} as an id. whereNumber() admits any run of digits,
     * and one longer than a bigint would reach Postgres as an error -- a 500
     * that differs from the 404 every other miss gets.
     */
    private function id(string $vehicle): ?int
    {
        $id = filter_var($vehicle, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
