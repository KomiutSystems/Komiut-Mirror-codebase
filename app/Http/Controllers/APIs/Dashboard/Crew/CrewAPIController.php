<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Crew;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Email;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Http\Controllers\APIs\Dashboard\Settings\RolesController;
use App\Services\Driver\VehicleAssignment;
use App\Services\Sql\LikeSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @group Crew
 *
 * The SACCO's people, and the buses they are on.
 *
 * WHY THIS EXISTS RATHER THAN vehicles/users. The crews screen was rendering
 * `vehicle_users` — a table of ASSIGNMENTS — as though it were a directory of
 * PEOPLE. In production that produced three visible bugs at once, measured on
 * NICCO MOVERS: 261 assignment rows for 179 people, so one investor appeared on
 * the page 40 times and another 20 times; seven drivers with no assignment row
 * were absent from a page that is supposed to list the crew; and everyone who
 * had ever been moved between buses showed up once per bus with a "—" vehicle
 * and an "Ended" status, which reads as a broken account rather than as history.
 *
 * This lists PEOPLE. Current assignment is an attribute of the person, and past
 * assignments are history you ask for, not rows you scroll past.
 *
 * WHO COUNTS AS CREW. Deliberately not "type = driver". Three things are true of
 * this fleet at once:
 *
 *   - `users.type` and the spatie role disagree, fleet-wide. All 171 NICCO
 *     drivers carry the role `Conductor`, not `Driver`, while their type says
 *     `driver`. The badge on the old screen rendered the TYPE, so the labels
 *     people read and the permissions they actually hold were two different
 *     things.
 *   - An investor owns buses and drives one. All 12 NICCO investors are
 *     `type = admin` and hold live assignments, so any filter on type alone
 *     hides exactly the people the SACCO most wants to see.
 *   - Queue supervisors are crew for this purpose: they work the stage, and the
 *     SACCO manages them from the same screen.
 *
 * So crew is the UNION: anyone whose type is driver, OR who holds one of the
 * operational roles, OR who holds Investor AND has an assignment. That last
 * clause is what keeps a purely financial investor out of an operations list
 * while keeping the investor-driver in it.
 *
 * ASSIGNMENT GOES THROUGH VehicleAssignment, NEVER STRAIGHT INTO vehicle_users.
 * The mobile driver login already assigns by plate, and it does considerably
 * more than insert a row: it closes the driver's other open assignments, releases
 * whoever was still on the bus, cancels the queue that driver left open so it
 * does not silently pass to the new crew, raises VehicleCrewChanged so the
 * displaced driver is told, and records a RapidReassignDetector fraud signal.
 * If this screen wrote the row itself, a bus assigned here and a bus assigned by
 * login would end up in different states — most obviously two live drivers on
 * one vehicle, each with a session the other cannot see.
 */
class CrewAPIController extends Controller
{
    use PaginatesResults;

    /**
     * Roles that make someone crew, as opposed to office staff. Investor is
     * handled separately — see the class docblock.
     */
    private const CREW_ROLES = [Roles::DRIVER, Roles::CONDUCTOR, Roles::QUEUE_SUPERVISOR];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List the SACCO's crew
     *
     * One row per PERSON. `vehicle` is their current open assignment, or null.
     *
     * @authenticated
     *
     * @queryParam search string Name, phone, email or plate. Example: KDY
     * @queryParam role string Filter to one role. Example: Driver
     * @queryParam assigned string `yes` or `no` — filter by whether they are on a bus. Example: no
     * @queryParam status string `active` or `inactive`. Example: active
     */
    public function index(Request $request): JsonResponse
    {
        $saccoId = auth()->user()->currentSaccoId();

        // User carries no SaccoScope (SaccoScope reads Auth::user(), so scoping
        // the user model risks recursion during authentication), which makes
        // this where() the actual tenant boundary for the whole endpoint.
        $query = User::query()
            ->when($saccoId !== null, fn ($q) => $q->where('users.sacco_id', $saccoId))
            ->when($saccoId === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->with(['roles:id,name'])
            ->where(function ($q) {
                $q->where('type', UserType::Driver)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', self::CREW_ROLES))
                    // The investor who also drives, found by an OPEN assignment
                    // and nothing else.
                    //
                    // This used to also match on `vehicles.user_id`, with a
                    // comment calling that "the ownership column". It is not.
                    // vehicles.user_id records whoever last SAVED the row — the
                    // add/edit endpoint reassigned it on every write until that
                    // was fixed — and 168 of NICCO's 180 vehicles point at the
                    // migration account. So the branch was wrong twice over: it
                    // found nothing for a real investor whose buses all carry the
                    // migration account, and it would have put ANY account that
                    // happened to be a heavy last-saver onto the crew page for
                    // the entire SACCO if it also held the Investor role.
                    //
                    // Ownership genuinely lives in vehicle_users: at NICCO ten
                    // investors hold open assignments, one across 40 buses and
                    // another across 20. status = true AND end_date IS NULL is
                    // the house definition of open — see VehicleAssignment and
                    // ResolvesDriverVehicle, which both use exactly that. The
                    // old branch checked only end_date, so a SUSPENDED
                    // assignment still read as current.
                    ->orWhere(fn ($inv) => $inv
                        ->whereHas('roles', fn ($r) => $r->where('name', Roles::INVESTOR))
                        ->whereHas('vehicle_users', fn ($vu) => $vu
                            ->where('status', true)
                            ->whereNull('end_date'))
                    );
            });

        if (filled($request->search)) {
            $needle = '%'.$request->search.'%';
            $query->where(function ($q) use ($needle) {
                $q->where('firstname', LikeSql::op(), $needle)
                    ->orWhere('lastname', LikeSql::op(), $needle)
                    ->orWhere('phone', LikeSql::op(), $needle)
                    ->orWhere('email', LikeSql::op(), $needle)
                    // Searching a plate finds the person on that bus — the way a
                    // dispatcher actually thinks about their crew.
                    // status too, not just end_date: the same house definition of
                    // an open assignment the investor branch above uses.
                    ->orWhereHas('vehicle_users', fn ($vu) => $vu
                        ->where('status', true)
                        ->whereNull('end_date')
                        ->whereHas('vehicle', fn ($v) => $v->where('plate', LikeSql::op(), $needle)));
            });
        }

        if (filled($request->role)) {
            $query->whereHas('roles', fn ($r) => $r->where('name', $request->role));
        }

        if ($request->assigned === 'yes') {
            $query->whereHas('vehicle_users', fn ($vu) => $vu->whereNull('end_date'));
        } elseif ($request->assigned === 'no') {
            $query->whereDoesntHave('vehicle_users', fn ($vu) => $vu->whereNull('end_date'));
        }

        if ($request->status === 'active') {
            $query->where('status', true);
        } elseif ($request->status === 'inactive') {
            $query->where('status', false);
        }

        $query->orderBy('firstname')->orderBy('lastname');

        $__meta = $this->pageMeta($query, $request, 20);
        $page = max(1, (int) ($request->page ?: 1));
        $people = $query->skip(($page - 1) * 20)->take(20)->get();

        // One extra query for the whole page rather than one per row.
        $assignments = VehicleUser::withoutGlobalScopes()
            ->whereIn('user_id', $people->pluck('id'))
            ->whereNull('end_date')
            ->with('vehicle:id,plate,sacco_id')
            ->get()
            ->groupBy('user_id');

        return response()->json(array_merge([
            'crew' => $people->map(fn (User $u) => $this->present($u, $assignments->get($u->id))),
            // Whole-set totals, NOT page totals. The screen renders these as a
            // headline ("13 named after a bus rather than a person"), and a
            // headline computed from the 20 rows in front of you is a lie that
            // changes when you turn the page.
            'counts' => $this->counts(clone $query),
            // So the role dropdown can be built without a second call, and
            // without offering roles this caller would be refused for. The
            // ceiling is enforced again on write; this is the UI's copy of it.
            'assignable_roles' => $this->assignableRoles(),
        ], $__meta));
    }

    /**
     * How many of the WHOLE filtered set carry each flag.
     *
     * Computed in PHP over a lean fetch rather than in SQL. The plate-shaped
     * name test is a regex over concatenated, punctuation-stripped columns, and
     * expressing that in portable SQL costs more than it saves at this size: a
     * SACCO's crew is hundreds of people, not millions. NICCO — the largest on
     * the platform — has 227.
     *
     * Capped, and the cap is REPORTED rather than silently truncating into a
     * number that looks authoritative.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return array<string, mixed>
     */
    private function counts($query): array
    {
        $cap = 2000;

        $people = $query->reorder()->with(['roles:id,name'])
            ->take($cap + 1)->get(['users.id', 'users.firstname', 'users.lastname', 'users.type']);

        $capped = $people->count() > $cap;
        $people = $people->take($cap);

        $openCounts = VehicleUser::withoutGlobalScopes()
            ->whereIn('user_id', $people->pluck('id'))
            ->whereNull('end_date')
            ->selectRaw('user_id, COUNT(*) as n')
            ->groupBy('user_id')
            ->pluck('n', 'user_id');

        $plate = 0;
        $multiple = 0;
        $unassigned = 0;
        $mismatch = 0;

        foreach ($people as $person) {
            $roles = $person->roles->pluck('name');
            $open = (int) ($openCounts[$person->id] ?? 0);

            if ($this->looksLikeAPlate($person)) {
                $plate++;
            }
            if ($open > 1) {
                $multiple++;
            }
            if ($open === 0) {
                $unassigned++;
            }
            if ($this->roleTypeMismatch($person, $roles)) {
                $mismatch++;
            }
        }

        return [
            'total' => $people->count(),
            'named_after_a_bus' => $plate,
            'holding_more_than_one_bus' => $multiple,
            'unassigned' => $unassigned,
            'role_type_mismatch' => $mismatch,
            'capped' => $capped,
            'cap' => $capped ? $cap : null,
        ];
    }

    /**
     * Does this person's account type disagree with the roles they hold?
     *
     * BOTH DIRECTIONS. This used to fire only for `type = driver` with no Driver
     * role, which missed the commonest case on this platform by far: 37 of
     * NICCO's 40 `type = admin` accounts hold only the Investor role and none of
     * the permissions an admin needs, so every edit they attempt 403s and the
     * screen gave no hint why. That is the disagreement worth surfacing.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $roles
     */
    private function roleTypeMismatch(User $user, $roles): bool
    {
        // Says driver, does not hold the Driver role.
        if ($user->type === UserType::Driver && ! $roles->contains(Roles::DRIVER)) {
            return true;
        }

        // Says admin, holds no role that can actually administer anything.
        if ($user->type === UserType::Admin && ! $roles->contains(Roles::SACCO_ADMIN)) {
            return true;
        }

        // Holds an operational role but the account type says passenger — the
        // account was never promoted and will fail type-based gates.
        if ($user->type === UserType::Passenger && $roles->intersect(self::CREW_ROLES)->isNotEmpty()) {
            return true;
        }

        return false;
    }

    /**
     * The roles this caller may hand out, already filtered by their own ceiling.
     *
     * Roles::saccoAssignable() is the outer list; a caller can only grant what
     * they themselves hold, so an Operations Manager does not get to offer
     * SACCO Admin. Both rules are re-applied on write — this is the UI's copy,
     * not the boundary.
     *
     * @return array<int, string>
     */
    private function assignableRoles(): array
    {
        $caller = auth()->user();

        if ($caller === null) {
            return [];
        }

        if ($caller->isSuperAdmin()) {
            return Roles::saccoAssignable();
        }

        $held = $caller->getAllPermissions()->pluck('name');

        return array_values(array_filter(
            Roles::saccoAssignable(),
            static function (string $name) use ($held): bool {
                $role = Role::where('guard_name', 'web')->where('name', $name)
                    ->with('permissions:id,name')->first();

                if ($role === null) {
                    return false;
                }

                return $role->permissions->pluck('name')->diff($held)->isEmpty();
            }
        ));
    }

    /**
     * Update a crew member's details
     *
     * The fix for the placeholder-name problem: 171 of NICCO's drivers are named
     * after their bus — firstname "KDY", lastname "759D" — because the legacy
     * import created one account per plate. They are unsearchable by name and
     * indistinguishable from each other on any screen. This is where a SACCO
     * turns them into people.
     *
     * Deliberately NOT editable here: `type`, `sacco_id`, `financier`, and roles.
     * Type and sacco decide which tenant someone belongs to, financier decides
     * which bank's fleet they can read, and roles are grants — all three are
     * authorization, and authorization changes belong on the endpoint that
     * already checks a permission ceiling and writes an audit record
     * (POST saccos/members/{user}/roles).
     *
     * @authenticated
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->findCrew($id);
        if ($user === null) {
            return response()->json(['error' => 'That person is not in your SACCO.'], 404);
        }
        if (! auth()->user()->can('Edit Sacco Members')) {
            return response()->json(['error' => 'You do not have permission to edit members.'], 403);
        }

        // Normalise before validating, so `unique:users,email` compares like for
        // like. PostgreSQL is case-sensitive, so without this an admin could
        // save a crew email that collides with another account in every way that
        // matters to a person but not to the unique index.
        if ($request->filled('email')) {
            $request->merge(['email' => Email::normalise((string) $request->input('email'))]);
        }

        $data = Validator::make($request->all(), [
            'firstname' => 'required|string|max:100',
            'lastname' => 'nullable|string|max:100',
            // Unique across the platform, not the SACCO: phone is the driver's
            // whole credential on the mobile app (phone + plate, no password),
            // so a collision would let two people sign in as one another.
            'phone' => 'required|string|max:20|unique:users,phone,'.$user->id,
            'email' => 'nullable|email|max:150|unique:users,email,'.$user->id,
            'status' => 'boolean|nullable',
        ])->validate();

        $user->fill([
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'] ?? $user->lastname,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? $user->email,
        ]);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $user->status = (bool) $data['status'];
        }

        $user->save();

        return response()->json(['crew' => $this->present($user->fresh(['roles']), $this->openFor($user))]);
    }

    /**
     * Put a crew member on a vehicle
     *
     * The same operation the mobile driver login performs, through the same
     * service — see the class docblock for why that matters. The response
     * reports what else moved, because assigning a bus is rarely just one change:
     * it can close the driver's previous assignment, release whoever was on this
     * bus, and cancel the queue they had open.
     *
     * @authenticated
     *
     * @bodyParam vehicle_id integer required The bus. Example: 151
     */
    public function assign(Request $request, int $id, VehicleAssignment $assignments): JsonResponse
    {
        $user = $this->findCrew($id);
        if ($user === null) {
            return response()->json(['error' => 'That person is not in your SACCO.'], 404);
        }
        if (! auth()->user()->can('Edit Vehicle Users') && ! auth()->user()->can('Add Vehicle Users')) {
            return response()->json(['error' => 'You do not have permission to assign crew.'], 403);
        }

        Validator::make($request->all(), [
            'vehicle_id' => 'required|integer|min:1',
        ])->validate();

        // Scoped read: `exists:vehicles,id` would only prove the bus exists.
        $vehicle = Vehicle::find((int) $request->vehicle_id);
        if ($vehicle === null) {
            return response()->json(['error' => 'That vehicle is not in your SACCO.'], 404);
        }

        // The driver and the bus must share a SACCO. This is the same rule the
        // mobile login enforces, and for the same reason: a plate is painted on
        // the side of the bus and readable by anyone, so it is not a secret —
        // what it must not do is move someone onto another SACCO's fleet.
        if ($user->sacco_id === null || (int) $user->sacco_id !== (int) $vehicle->sacco_id) {
            return response()->json(['error' => 'That vehicle belongs to another SACCO.'], 403);
        }

        $before = $this->openFor($user);
        $assignment = $assignments->assign($user, $vehicle);

        return response()->json([
            'assignment' => [
                'id' => $assignment->id,
                'vehicle' => ['id' => $vehicle->id, 'plate' => $vehicle->plate],
                'started_at' => optional($assignment->start_date)->toIso8601String(),
                // True when they were already on this bus — the dashboard should
                // say "already assigned" rather than claim it moved something.
                'was_already_assigned' => $before !== null
                    && $before->contains(fn ($a) => (int) $a->vehicle_id === (int) $vehicle->id),
            ],
            'crew' => $this->present($user->fresh(['roles']), $this->openFor($user)),
        ]);
    }

    /**
     * Take a crew member off their vehicle
     *
     * Closes the open assignment rather than deleting it: who crewed which bus
     * on which day is what every takings dispute is settled with.
     *
     * @authenticated
     */
    public function unassign(Request $request, int $id): JsonResponse
    {
        $user = $this->findCrew($id);
        if ($user === null) {
            return response()->json(['error' => 'That person is not in your SACCO.'], 404);
        }
        if (! auth()->user()->can('Edit Vehicle Users')) {
            return response()->json(['error' => 'You do not have permission to assign crew.'], 403);
        }

        $closed = VehicleUser::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->whereHas('vehicle', fn ($v) => $v->where('sacco_id', auth()->user()->currentSaccoId()))
            ->update(['end_date' => now(), 'status' => false]);

        return response()->json([
            'closed' => $closed,
            'crew' => $this->present($user->fresh(['roles']), $this->openFor($user)),
        ]);
    }

    /**
     * Buses this crew member can be put on
     *
     * The vehicle picker the assign action needs and did not have. index()
     * returns no vehicle list, and the general `GET vehicles` endpoint is gated
     * on `View Vehicles` — a permission `Edit Vehicle Users` does not imply and
     * Operations Manager does not hold. So the one screen that assigns buses had
     * no way to list them.
     *
     * Each bus reports who is on it now, because reassigning is rarely a fresh
     * start: putting a driver on a taken bus releases whoever was there and
     * cancels their open queue, and an admin should see that before they click,
     * not after.
     *
     * @authenticated
     *
     * @queryParam search string Filter by plate or fleet number. Example: KDY
     * @queryParam free_only boolean Only buses with nobody on them. Example: true
     */
    public function assignableVehicles(Request $request): JsonResponse
    {
        if (! auth()->user()->can('Edit Vehicle Users') && ! auth()->user()->can('Add Vehicle Users')) {
            return response()->json(['error' => 'You do not have permission to assign crew.'], 403);
        }

        $saccoId = auth()->user()->currentSaccoId();

        if ($saccoId === null) {
            return response()->json(['vehicles' => [], 'total' => 0]);
        }

        // Vehicle IS SaccoScoped, but the scope steps aside for a tenantless
        // caller, so the explicit filter is what actually holds here.
        $vehicles = Vehicle::query()
            ->where('sacco_id', $saccoId)
            ->when(filled($request->search), fn ($q) => $q->where(fn ($w) => $w
                ->where('plate', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('fleet_no', LikeSql::op(), '%'.$request->search.'%')))
            ->orderBy('plate')
            ->limit(500)
            ->get(['id', 'plate', 'fleet_no', 'status']);

        $occupants = VehicleUser::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicles->pluck('id'))
            ->whereNull('end_date')
            ->with('user:id,firstname,lastname')
            ->get()
            ->groupBy('vehicle_id');

        $rows = $vehicles->map(function (Vehicle $v) use ($occupants) {
            $on = $occupants->get($v->id) ?? collect();

            return [
                'id' => (int) $v->id,
                'plate' => $v->plate,
                'fleet_no' => $v->fleet_no,
                'active' => (bool) $v->status,
                'occupied_by' => $on->map(fn (VehicleUser $a) => [
                    'user_id' => $a->user?->id,
                    'name' => trim(($a->user?->firstname ?? '').' '.($a->user?->lastname ?? '')),
                ])->values(),
            ];
        });

        if ($request->boolean('free_only')) {
            $rows = $rows->filter(fn (array $r) => $r['occupied_by']->isEmpty())->values();
        }

        return response()->json(['vehicles' => $rows->values(), 'total' => $rows->count()]);
    }

    /**
     * Change a crew member's roles
     *
     * The operation this screen most needed and did not have. update() edits a
     * person's details and deliberately refuses to touch roles, so changing
     * "this account says driver but holds only Investor" — true of 37 of NICCO's
     * 40 admin accounts, and the reason their edits 403 — meant leaving the crew
     * page for the members screen.
     *
     * The GUARDS ARE NOT RE-IMPLEMENTED HERE. Role changes are grants, and the
     * rules that make them safe already exist and are already audited:
     * RolesController::assignMemberRoles enforces same-SACCO, the
     * `Edit Sacco Members` permission, the assignable-role list, and a
     * permission ceiling that stops a caller granting beyond what they hold. A
     * second copy of that logic is a second place for it to drift, so this
     * delegates to it and adds only what the crew screen needs back: the
     * refreshed crew row, so the table can update in place.
     *
     * @authenticated
     *
     * @bodyParam roles string[] required The complete set of roles this person should hold. Example: ["Driver"]
     *
     * Refusals come from those shared guards via abort(), so they render in
     * Laravel's shape — {"message": ...} — not this controller's {"error": ...}.
     *
     * @response 403 {"message": "These roles exceed your own permissions: Edit Payment Settings"}
     */
    public function changeRole(Request $request, int $id, RolesController $roles): JsonResponse
    {
        $user = $this->findCrew($id);

        if ($user === null) {
            return response()->json(['error' => 'That person is not in your SACCO.'], 404);
        }

        // Never yourself. Dropping your own SACCO Admin role is a one-way door:
        // the next request has no permission to put it back, and the screen
        // offers no way to notice before it happens.
        if ($user->id === auth()->id()) {
            return response()->json(['error' => 'You cannot change your own roles.'], 422);
        }

        $response = $roles->assignMemberRoles($request, $user);

        // A refusal from the shared guards aborts, so it never reaches here —
        // it propagates and Laravel renders it. This only catches a future
        // non-throwing failure path, and passes it through verbatim rather than
        // restating it in our own words.
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        return response()->json([
            'crew' => $this->present($user->fresh(['roles']), $this->openFor($user)),
        ]);
    }

    /**
     * Release a crew member from your SACCO
     *
     * The other half of the street-onboarding gate. driver/onboard is public and
     * matches on a phone number, so it will no longer move a driver who already
     * belongs to a SACCO — otherwise anyone could type a stranger's number into
     * their own SACCO and take the driver. But drivers change SACCO constantly,
     * and refusing that with no way to do it properly would just move the
     * problem to support.
     *
     * So the move is two same-tenant writes rather than one cross-tenant one:
     * the SACCO that HAS the driver releases them here, and the SACCO that wants
     * them onboards them normally, which works again the moment `sacco_id` is
     * null. Neither side ever writes to the other's rows, and both halves are
     * done by an accountable, authenticated admin.
     *
     * Open vehicle assignments are closed at the same time. A released driver
     * still holding a bus in your fleet is a rota that lies.
     *
     * @authenticated
     *
     * @response 404 {"error": "That person is not in your SACCO."}
     * @response 403 {"error": "You do not have permission to edit members."}
     */
    public function release(Request $request, int $id): JsonResponse
    {
        $user = $this->findCrew($id);
        if ($user === null) {
            return response()->json(['error' => 'That person is not in your SACCO.'], 404);
        }
        if (! auth()->user()->can('Edit Sacco Members')) {
            return response()->json(['error' => 'You do not have permission to edit members.'], 403);
        }

        // Never release yourself, and never release an admin. Both would be a
        // way to drop a colleague out of the SACCO they administer.
        if ($user->id === auth()->id() || $user->type === UserType::Admin) {
            return response()->json(['error' => 'Admins cannot be released from here.'], 422);
        }

        $saccoId = (int) auth()->user()->currentSaccoId();

        $closed = VehicleUser::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->whereHas('vehicle', fn ($v) => $v->where('sacco_id', $saccoId))
            ->update(['end_date' => now(), 'status' => false]);

        $user->forceFill(['sacco_id' => null])->save();

        return response()->json([
            'released' => true,
            'closed_assignments' => $closed,
            'message' => 'Released. They can now be signed up by another SACCO.',
        ]);
    }

    /**
     * One crew member's assignment history
     *
     * The rows the old screen showed inline. Kept, because "who was on this bus
     * in March" is a real question — just not one that belongs in a directory.
     *
     * @authenticated
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $user = $this->findCrew($id);
        if ($user === null) {
            return response()->json(['error' => 'That person is not in your SACCO.'], 404);
        }

        $rows = VehicleUser::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereHas('vehicle', fn ($v) => $v->where('sacco_id', auth()->user()->currentSaccoId()))
            ->with('vehicle:id,plate')
            ->orderByDesc('start_date')->limit(100)->get();

        return response()->json(['history' => $rows->map(fn (VehicleUser $a) => [
            'id' => $a->id,
            'vehicle' => $a->vehicle ? ['id' => $a->vehicle->id, 'plate' => $a->vehicle->plate] : null,
            'started_at' => optional($a->start_date)->toIso8601String(),
            'ended_at' => optional($a->end_date)->toIso8601String(),
            'status' => (bool) $a->status,
        ])]);
    }

    /**
     * Resolve a crew member inside the caller's SACCO.
     *
     * User has no SaccoScope, so this is the tenant boundary for every method
     * here — not a convenience.
     */
    private function findCrew(int $id): ?User
    {
        $saccoId = auth()->user()->currentSaccoId();
        if ($saccoId === null) {
            return null;
        }

        return User::where('id', $id)->where('sacco_id', $saccoId)->first();
    }

    /** @return \Illuminate\Support\Collection<int, VehicleUser>|null */
    private function openFor(User $user)
    {
        $open = VehicleUser::withoutGlobalScopes()
            ->where('user_id', $user->id)->whereNull('end_date')
            ->with('vehicle:id,plate,sacco_id')->get();

        return $open->isEmpty() ? null : $open;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, VehicleUser>|null  $open
     * @return array<string, mixed>
     */
    private function present(User $user, $open): array
    {
        $roles = $user->getRoleNames()->values();

        return [
            'id' => $user->id,
            'name' => trim(($user->firstname ?? '').' '.($user->lastname ?? '')),
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'phone' => $user->phone,
            'email' => $user->email,
            'type' => $user->type instanceof UserType ? $user->type->value : $user->type,
            'roles' => $roles,
            'status' => (bool) $user->status,

            // The current bus. An array, not a single object: the data allows a
            // person to hold several open assignments, and production has people
            // holding dozens. Collapsing that to one would hide it — and hiding
            // it is how it got to forty.
            'vehicles' => $open === null ? [] : $open->map(fn (VehicleUser $a) => [
                'assignment_id' => $a->id,
                'id' => $a->vehicle?->id,
                'plate' => $a->vehicle?->plate,
                'since' => optional($a->start_date)->toIso8601String(),
            ])->values(),

            // Switched off BY THE PLATFORM, with a reason — as distinct from
            // switched off by this SACCO, which is `status`. Without these the
            // screen shows an inactive account and cannot say who did it or
            // why, so a SACCO admin re-enables someone the platform suspended.
            'suspended_at' => optional($user->suspended_at)->toIso8601String(),
            'suspension_reason' => $user->suspension_reason,

            // Flags the UI can act on rather than re-deriving.
            'flags' => [
                // firstname "KDY", lastname "759D" — a bus, not a person. 171 of
                // NICCO's crew look like this after the legacy import.
                'name_looks_like_a_plate' => $this->looksLikeAPlate($user),
                // Holds more than one bus at once. Legitimate for an investor,
                // almost certainly wrong for a driver.
                'multiple_vehicles' => $open !== null && $open->count() > 1,
                'unassigned' => $open === null,
                // type says driver, role says something else (or nothing). True
                // for every one of NICCO's 171 drivers today.
                // Both directions — see roleTypeMismatch(). The commonest
                // case on this platform is an admin holding only Investor.
                'role_type_mismatch' => $this->roleTypeMismatch($user, $roles),
            ],
        ];
    }

    /**
     * "KDY" + "759D" is a number plate wearing a person's name. Matching the
     * shape rather than a fixed pattern, because this fleet has KXX 000X and
     * four-letter series like KTWA 463G side by side.
     */
    private function looksLikeAPlate(User $user): bool
    {
        $joined = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', ($user->firstname ?? '').($user->lastname ?? '')));

        return $joined !== '' && preg_match('/^K[A-Z]{2,3}[0-9]{3}[A-Z]?$/', $joined) === 1;
    }
}
