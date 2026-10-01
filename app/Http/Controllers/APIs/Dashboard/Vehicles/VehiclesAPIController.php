<?php

namespace App\Http\Controllers\APIs\Dashboard\Vehicles;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use App\Models\Sacco;
use App\Models\SaccoVehicle;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Sql\LikeSql;
use App\Services\Sql\PlateSql;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class VehiclesAPIController extends Controller
{
    use PaginatesResults;

    /**
     * The `financier` filter value for "no bank recorded" (financier IS NULL),
     * and the matching key in `financier_counts`. A word rather than an empty
     * value, because an empty filter already means "no filter".
     */
    private const NO_FINANCIER = 'none';

    /** Audit action and console event for an actual change of a vehicle's bank. */
    private const FINANCIER_CHANGED = 'vehicles.financier.changed';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List vehicles
     *
     * The caller's fleet, 20 per page, newest first. Tenancy is applied by the
     * model's global scopes, not here: a SACCO user sees their own SACCO, a
     * bank user only the buses their bank financed, a superadmin every SACCO.
     *
     * `financier_counts` answers "which of our buses is under which bank" at a
     * glance. It is counted over the caller's whole tenancy-scoped fleet (and
     * the `sacco` a superadmin narrowed to), deliberately IGNORING `financier`,
     * `search`, `seat`, `vehicles` and the page, so the filter chips built on it
     * keep their numbers while the list beneath them is filtered.
     *
     * @authenticated
     *
     * @queryParam page integer Page number, 20 rows each. Default 1. Example: 1
     * @queryParam sacco integer Narrow to one SACCO (superadmin; a SACCO user is already confined to their own). Example: 4
     * @queryParam seat integer Narrow to one seat layout id. Example: 2
     * @queryParam vehicles string Comma-separated vehicle ids, optionally bracketed. Example: [151,152]
     * @queryParam search string Plate (spacing and case ignored), till number or merchant short code. Example: kdk380z
     * @queryParam financier string Narrow to the buses one bank finances: NCBA, coop-bank, or none for buses with no bank recorded. Any other value is a 400. Example: none
     *
     * @response 200 scenario="success" {"vehicles":[{"id":151,"plate":"KDK 380Z","fleet_no":"12","till_number":"5123456","merchant_short_code":"5123456","ncba_till":null,"coop_till":null,"financier":"NCBA","brand":"komiut","sacco_id":4,"user_id":9,"seat_id":2,"mpesa_payment_setting_id":null,"status":true,"created_at":"2026-08-01T08:00:00.000000Z","updated_at":"2026-10-02T08:00:00.000000Z"}],"total":126,"per_page":20,"current_page":1,"last_page":7,"financier_counts":{"NCBA":126,"coop-bank":54,"none":0}}
     * @response 400 scenario="unknown financier filter" {"errors":{"financier":["The selected financier is invalid."]}}
     */
    public function getVehicles(Request $request)
    {
        // Blank means "no filter", the same as absent — the dashboard's "All"
        // chip may well post an empty value. The is_string guard keeps
        // financier[]=x away from trim(); the rule below rejects it instead.
        $financierFilter = $request->input('financier');
        if (is_string($financierFilter) && trim($financierFilter) === '') {
            $financierFilter = null;
        }

        // An allow-list, and a loud one. Answering an unknown value with an
        // unfiltered list would show "every bus" under a chip that says one
        // bank, and answering it with an empty list would read as "this bank
        // finances nothing here" — both wrong in a way nobody would notice.
        $validator = Validator::make(['financier' => $financierFilter], [
            'financier' => ['nullable', 'string', Rule::in([...Financier::values(), self::NO_FINANCIER])],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $vehicles = Vehicle::with(['user', 'seat', 'sacco']);

        // The bank boundary is NOT applied here: Vehicle carries
        // BelongsToFinancier, so the global scope has already constrained this
        // query to the fleet a bank user financed. Applying it again by hand
        // would only repeat the same predicate.
        //
        // Keeping it on the model rather than in this controller is the whole
        // point — a per-controller boundary is how Cash, Mpesa and
        // QrcodePayment came to be unscoped in the first place, because the
        // next endpoint someone writes inherits a model's scope for free and
        // inherits nothing from a controller.

        $veh = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($veh as $vehicle) {
            $v = trim($vehicle);
            if ($v != '') {
                array_push($all_vehicles, trim($vehicle));
            }
        }

        if ($request->sacco > 0) {
            $vehicles = $vehicles->where('sacco_id', $request->sacco);
        }

        if ($request->seat > 0) {
            $vehicles = $vehicles->where('seat_id', $request->seat);
        }
        if (count($all_vehicles) > 0) {
            $vehicles = $vehicles->whereIn('id', $all_vehicles);
        }

        // On top of FinancierScope, never instead of it: a bank user asking for
        // the other bank's buses gets the intersection, which is nothing.
        // Qualified, for the same reason the scopes qualify theirs.
        if ($financierFilter === self::NO_FINANCIER) {
            $vehicles = $vehicles->whereNull('vehicles.financier');
        } elseif ($financierFilter !== null) {
            $vehicles = $vehicles->where('vehicles.financier', $financierFilter);
        }

        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            // The PLATE is matched normalised, the way driver login and the live
            // map already match it, so "kdk380z" finds "KDK 380Z". The live map
            // filters its fleet list here and its pins through
            // VehicleLocationsReadController; those two used different rules, so
            // a space-less plate matched the map and not the list beside it.
            $plate = PlateSql::matchBinding((string) $request->search);

            $vehicles = $vehicles->where(function ($query) use ($request, $plate) {
                $query->whereRaw(PlateSql::matchSql('plate'), [$plate])
                    ->orWhere('till_number', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('merchant_short_code', LikeSql::op(), '%'.$request->search.'%');
            });
        }
        $__meta = $this->pageMeta($vehicles, $request, 20);
        $vehicles = $vehicles->skip($offset)->take(20)
            ->orderBy('created_at', 'DESC')->get();

        // Resource-backed response: same {"vehicles":[...]} envelope (wrapping is
        // disabled globally), but the field shape is now an explicit contract.
        return response()->json(array_merge(
            ['vehicles' => VehicleResource::collection($vehicles)],
            $__meta,
            ['financier_counts' => $this->financierCounts($request)],
        ));
    }

    /**
     * How many of the caller's buses each bank finances, and how many carry no
     * bank at all.
     *
     * Built from a FRESH Vehicle query rather than a clone of the list's, so it
     * gets exactly the tenancy the list gets — SaccoScope, BrandScope and
     * FinancierScope are global scopes on the model — and none of the
     * narrowing the list then adds. That is the point: a chip reading "NCBA
     * 126" must still read 126 after the user clicks it or types in the search
     * box. `sacco` is the one request filter honoured, because for a superadmin
     * it IS the tenancy they are looking at; for a SACCO user it can only
     * repeat SaccoScope or select nothing.
     *
     * Counted live, not through pageMeta's one-minute cache: the screen this
     * feeds is where a SACCO corrects its banks bus by bus, and "No bank 37"
     * that does not drop to 36 after a save reads as a save that did not land.
     * It is one GROUP BY over a few hundred rows on the (financier, sacco_id)
     * index.
     *
     * Values are matched exactly, as the `financier` filter matches them, so a
     * chip and the list behind it can never disagree. The CHECK constraint on
     * the column (2026_08_28_120000) means nothing outside the enum or NULL can
     * be stored today; were it ever to happen, such a row would be counted
     * nowhere rather than miscounted as "none", which the filter would not
     * return.
     *
     * @return array<string, int>
     */
    private function financierCounts(Request $request): array
    {
        $counts = array_fill_keys([...Financier::values(), self::NO_FINANCIER], 0);

        $rows = Vehicle::query()
            ->when($request->sacco > 0, fn (Builder $query) => $query->where('sacco_id', $request->sacco))
            ->toBase()
            ->select('vehicles.financier')
            ->selectRaw('COUNT(*) AS vehicles_count')
            ->groupBy('vehicles.financier')
            ->get();

        foreach ($rows as $row) {
            $key = $row->financier ?? self::NO_FINANCIER;

            if (array_key_exists($key, $counts)) {
                $counts[$key] += (int) $row->vehicles_count;
            }
        }

        return $counts;
    }

    /**
     * Create or edit a vehicle
     *
     * One endpoint for both: `id` 0 creates (needs Add Vehicles), `id` > 0
     * edits that vehicle (needs Edit Vehicles), and an id outside the caller's
     * SACCO is a 404.
     *
     * On EDIT, `fleet_no`, `till_number`, `merchant_short_code`, `ncba_till`,
     * `coop_till` and `financier` are only written when the key is present in
     * the body; leave a key out to keep what is stored, send it as null to
     * clear it. On CREATE an absent key means null.
     *
     * `financier` — which bank finances the bus — is an authorization key (it
     * decides which bank is shown the bus and its money), so who may move it is
     * narrower than who may edit the vehicle:
     *   - a superadmin may set, change or clear it on any vehicle;
     *   - a holder of Edit Vehicle Bank may set, change or clear it on a vehicle
     *     in their own SACCO, on create as well as edit;
     *   - anyone else: on create it is ignored (the bus is created with no
     *     bank); on edit, re-sending the stored value is accepted and changes
     *     nothing, while any actual change is refused with a 403 and nothing at
     *     all is saved.
     * Every actual change is audited (vehicles.financier.changed) and raised on
     * the super console.
     *
     * @authenticated
     *
     * @bodyParam id integer required 0 to create, the vehicle id to edit. Example: 151
     * @bodyParam plate string required Unique across the platform. Example: KDK 380Z
     * @bodyParam seat string required Seat layout NAME (not id). Example: 14 Seater
     * @bodyParam sacco string SACCO NAME (not id). A SACCO user can only name their own; anything else is ignored. Example: NICCO MOVERS
     * @bodyParam status integer required 1 active, 0 inactive. Example: 1
     * @bodyParam fleet_no string The SACCO's own fleet number. Omit on edit to keep it. Example: 12
     * @bodyParam till_number integer Safaricom till. Omit on edit to keep it. Example: 5123456
     * @bodyParam merchant_short_code integer Safaricom merchant short code. Omit on edit to keep it. Example: 5123456
     * @bodyParam ncba_till string NCBA collection account (kept as text: leading zeros matter). Omit on edit to keep it. Example: 0012345678
     * @bodyParam coop_till string Co-op collection account. Omit on edit to keep it. Example: 0112345678
     * @bodyParam financier string The bank that finances the bus: NCBA, coop-bank, or null/empty for no bank. Omit on edit to keep it. See above for who may change it. Example: coop-bank
     *
     * @response 200 scenario="saved" {"success":"Vehicle saved successfully"}
     * @response 400 scenario="validation" {"errors":{"financier":["The selected financier is invalid."]}}
     * @response 401 scenario="no Add/Edit Vehicles permission" {"error":"Permissions to Add/Edit Vehicle Denied"}
     * @response 403 scenario="bank change without Edit Vehicle Bank" {"error":"You do not have permission to change which bank finances this vehicle"}
     * @response 404 scenario="vehicle not in your SACCO" {"message":"No query results for model [App\\Models\\Vehicle]."}
     */
    public function addVehicle(Request $request)
    {
        // Add and Edit are DIFFERENT permissions, and this endpoint does both
        // depending on whether an id is present. OR-ing them meant `Add
        // Vehicles` alone was enough to EDIT any bus in the SACCO — including
        // its till_number, merchant_short_code, ncba_till and coop_till, the
        // fields that decide which account a bus's fares land in. The Investor
        // bundle holds `Add Vehicles`, so every investor could redirect the
        // money of all 180 buses in their SACCO.
        $isEdit = (int) $request->input('id') > 0;
        $needed = $isEdit ? 'Edit Vehicles' : 'Add Vehicles';

        if (auth()->user()->can($needed)) {
            // Blank and absent must mean the same thing before anything reads
            // this field: an edit form that posts an empty box is not asking
            // for a financier, and '' would otherwise fail the allow-list below
            // and 400 an edit that never touched the field. The is_string guard
            // is for the cast — financier[]=x would raise an "Array to string
            // conversion" here; a non-string is left for the rule to reject.
            if (is_string($request->input('financier')) && trim($request->input('financier')) === '') {
                $request->merge(['financier' => null]);
            }

            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'plate' => 'required|string|unique:vehicles,plate,'.$request->id,
                'fleet_no' => 'string|nullable',
                'till_number' => 'integer|nullable',
                'sacco' => 'string|nullable',
                'seat' => 'required|exists:seats,name',
                'merchant_short_code' => 'integer|nullable',
                // The BANK's collection account, distinct from the Safaricom
                // till above. Strings, not integers: bank account numbers can
                // carry leading zeros, which an integer cast silently eats.
                'ncba_till' => 'string|nullable|max:30',
                'coop_till' => 'string|nullable|max:30',
                // An allow-list, not free text. This column decides which bank
                // is shown the vehicle and its money, so 'string|max:60' let a
                // typo ("NCBA " with a space, "ncba") quietly remove a bus from
                // the bank that financed it — with nothing to see in the UI.
                'financier' => ['nullable', Rule::enum(Financier::class)],
                'status' => 'required|min:0|integer',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $vehicle = new Vehicle;
            if ($isEdit) {
                // Scoped find, so an id from another tenant is "not found"
                // rather than editable. Vehicle carries SaccoScope, but
                // findOrFail is the kind of call that survives a later
                // withoutGlobalScopes refactor unnoticed — be explicit.
                $vehicle = Vehicle::where('id', (int) $request->input('id'))->firstOrFail();
            }
            // The bank as stored BEFORE this request, taken before anything
            // below can touch the row: the audit records an actual change, and
            // "actual" is measured against this. A new vehicle has none.
            $financierBefore = $vehicle->exists ? $vehicle->financier : null;

            $sacco = Sacco::where('name', $request->sacco)->first();
            if ($sacco != null) {
                $vehicle->sacco_id = $sacco->id;
            }
            $seat = Seat::where('name', $request->seat)->first();
            if ($seat != null) {
                $vehicle->seat_id = $seat->id;
            }
            $vehicle->plate = $request->plate;
            // On EDIT, only overwrite what the request actually mentions. These
            // three were written unconditionally, so a field the form did not
            // send was saved as NULL: the dashboard's payment sheet posts the
            // tills and not fleet_no, and every till saved there quietly wiped
            // the bus's fleet number. A key sent as null still clears the
            // field — $request->exists() is true for a present null — so
            // clearing on purpose keeps working.
            //
            // On CREATE an absent key is written as null, exactly as before:
            // there is nothing stored to keep, and leaving the attribute unset
            // would hand the column to whatever default the schema has rather
            // than the null this endpoint has always created with.
            foreach (['fleet_no', 'till_number', 'merchant_short_code'] as $field) {
                if (! $vehicle->exists || $request->exists($field)) {
                    $vehicle->{$field} = $request->input($field);
                }
            }
            // Only overwrite when supplied: an edit that does not mention a
            // bank till must not wipe one that was already issued.
            foreach (['ncba_till', 'coop_till'] as $field) {
                if ($request->exists($field)) {
                    $vehicle->{$field} = $request->input($field);
                }
            }

            // `financier` is NOT ordinary vehicle data. It is the key deciding
            // which bank is shown this vehicle and its money (FinancierScope)
            // and whose statement its collections land on
            // (bank:send-statement), so 'Edit Vehicles' alone — which every
            // Fleet Manager holds — must not move it. Leaving it in the loops
            // above once let any Fleet Manager take their bus out from under
            // NCBA's view by editing a text field.
            //
            // Who may write it:
            //
            //   - A superadmin, on any vehicle. Authoritative, as before.
            //
            //   - A holder of 'Edit Vehicle Bank' (SACCO Admin), on a vehicle
            //     in THEIR OWN SACCO, on create and edit alike, including
            //     clearing it. Until this existed only a superadmin could set
            //     the bank, and the SACCO — the one party that knows which bank
            //     financed which bus — could neither fill in a missing one (720
            //     buses platform-wide carry none) nor correct a wrong one. See
            //     mayMoveTheBankOf() for why "own SACCO" is checked here as well
            //     as by the scoped lookup above.
            //
            //   - Anyone else is refused only when EDITING and only on an
            //     actual CHANGE, both sides resolved through the enum first, so
            //     an edit form that faithfully round-trips the stored value
            //     stays a no-op and still saves — otherwise every Fleet Manager
            //     edit of an unrelated field would start failing. On CREATE
            //     there is no stored value to defend, so the field is dropped
            //     rather than refused: refusing would 403 the whole request and
            //     create no vehicle at all.
            if ($request->exists('financier')) {
                $submitted = Financier::tryParse($request->input('financier'));
                $caller = auth()->user();

                if ($caller->isSuperAdmin() || $this->mayMoveTheBankOf($caller, $vehicle)) {
                    $vehicle->financier = $submitted?->value;
                } elseif (! $vehicle->exists) {
                    // CREATE by someone who may not set the bank. A bus that
                    // cannot be added is a worse outcome than a bus added
                    // without a bank, and a SACCO Admin or a superadmin can
                    // assign the bank afterwards.
                } elseif ($submitted !== Financier::tryParse($vehicle->financier)) {
                    // 403, not the 401 this method returns for its own
                    // permission denial: the caller IS authenticated and may
                    // well hold 'Edit Vehicles'. It is this one field they are
                    // not allowed to move. Returned before save(), so nothing
                    // else in the request is written either.
                    return response()->json([
                        'error' => 'You do not have permission to change which bank finances this vehicle',
                    ], 403);
                }
            }
            // Only on CREATE. This ran on every save, so it recorded the last
            // person to touch the row rather than who owns the bus — which is
            // why 168 of NICCO's 180 vehicles point at the migration account.
            // On an edit it also handed the caller ownership of a bus that was
            // never theirs, one POST at a time.
            if (! $vehicle->exists) {
                $vehicle->user_id = Auth::user()->id;
            }
            $vehicle->status = $request->status;
            if ($vehicle->save()) {
                // After save(), so a refused or failed write is never recorded
                // as a reassignment, and the new vehicle has an id to point at.
                // Compared raw: both sides are what the column holds.
                if ($financierBefore !== $vehicle->financier) {
                    $this->recordFinancierChange($vehicle, $financierBefore, $vehicle->financier, ! $isEdit);
                }

                if ($sacco != null) {
                    if (SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', $sacco->id)
                        ->where('end_date', null)->count() == 0) {
                        $saccoVehicle = new SaccoVehicle;
                        $saccoVehicle->sacco_id = $sacco->id;
                        $saccoVehicle->vehicle_id = $vehicle->id;
                        $saccoVehicle->user_id = Auth::user()->id;
                        $saccoVehicle->start_date = Carbon::now();
                        if ($saccoVehicle->save()) {
                            SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', '<>', $sacco->id)
                                ->where('end_date', null)->update(['end_date' => Carbon::now()]);
                        }
                    }
                }

                return response()->json(['success' => 'Vehicle saved successfully']);
            } else {
                return response()->json(['error' => 'Unable to update vehicle'], 401);
            }
        } else {
            return response()->json(['error' => 'Permissions to Add/Edit Vehicle Denied'], 401);
        }
    }

    /**
     * May this (non-superadmin) caller set, change or clear the bank of this
     * vehicle?
     *
     * Only with 'Edit Vehicle Bank', and only for a vehicle that is — after
     * this request's own `sacco` has been applied — in the caller's own SACCO.
     *
     * The SACCO comparison is NOT redundant with the scoped lookup in
     * addVehicle(), for two callers that lookup lets through:
     *
     *   - A caller with NO SACCO. Vehicle opts into cross-tenant browsing so
     *     passengers can find a matatu, which means SaccoScope hands a
     *     tenantless caller every vehicle on the platform (a bank user, also
     *     tenantless by design, gets every bus its bank finances). A stray
     *     grant of this permission to such an account would otherwise let it
     *     re-bank every bus it can see, across every SACCO — and a bank could
     *     take buses off its own statement.
     *   - CREATE, where there is no lookup at all. The new vehicle's SACCO is
     *     whatever `sacco` resolved to (Sacco is SACCO-scoped too, so a SACCO
     *     user can only name their own), and a bus created with no SACCO is
     *     nobody's to bank.
     *
     * A caller who fails this falls through to the ordinary rule — dropped on
     * create, 403 on an actual change — rather than getting a separate error.
     */
    private function mayMoveTheBankOf(User $caller, Vehicle $vehicle): bool
    {
        $callerSaccoId = $caller->currentSaccoId();

        return $callerSaccoId !== null
            && $vehicle->sacco_id !== null
            && (int) $vehicle->sacco_id === (int) $callerSaccoId
            && $caller->can(Roles::EDIT_VEHICLE_BANK);
    }

    /**
     * Audit, then raise on the super console, one ACTUAL change of a vehicle's
     * bank — whoever made it, superadmins included.
     *
     * A bank change moves a bus's money out of one bank's dashboard and
     * statement and into another's, and since 'Edit Vehicle Bank' put that in
     * SACCO hands it is no longer only the platform making the move. The audit
     * row is the immutable answer to "who took this bus off our statement, and
     * when" when a bank asks; the notification is how the platform hears about
     * it before the bank does. Audit-first, as VehiclePaymentObserver does it,
     * so the alert carries a link to its record.
     *
     * Two kinds of change, raised differently:
     *
     *   - The bus LEAVES a bank (NCBA -> Co-op, or NCBA -> none). A bank stops
     *     seeing a bus it was shown yesterday — the case a bank will dispute.
     *     A 'high' alert, one per vehicle, never throttled.
     *   - A bank is filled in on a bus that had none. This is the backlog the
     *     SACCO is expected to work through (720 buses with no bank), so it is
     *     a 'review' item, and a run of them from one SACCO to one bank folds
     *     into a single notification with a count. Each one still gets its own
     *     audit row; only the console card is shared.
     *
     * Called after the save has succeeded, and it must never undo that: the
     * change is committed by the time this runs, so an audit or broadcast
     * failure is reported and swallowed rather than turned into an error for a
     * write that happened.
     */
    private function recordFinancierChange(Vehicle $vehicle, ?string $from, ?string $to, bool $onCreate): void
    {
        try {
            $saccoId = $vehicle->sacco_id !== null ? (int) $vehicle->sacco_id : null;
            $brand = $vehicle->brand !== null ? (string) $vehicle->brand : null;
            $subject = ['type' => 'vehicle', 'id' => (string) $vehicle->id];

            $data = [
                'vehicleId' => (int) $vehicle->id,
                'plate' => (string) $vehicle->plate,
                'saccoId' => $saccoId,
                'from' => $from,
                'to' => $to,
                'onCreate' => $onCreate,
            ];

            // sacco_id set, so the SACCO's own activity log shows the change
            // to the SACCO that made it (or had it made for them).
            $audit = AuditLogger::record(
                self::FINANCIER_CHANGED,
                $data,
                null,
                $subject,
                $brand,
                $saccoId,
            );

            $fromLabel = Financier::tryParse($from)?->label() ?? 'no bank';
            $toLabel = Financier::tryParse($to)?->label() ?? 'no bank';
            $leavesABank = $from !== null;

            app(PlatformNotifier::class)->dispatch(new PlatformEvent(
                event: self::FINANCIER_CHANGED,
                severity: $leavesABank ? 'high' : 'normal',
                class: $leavesABank ? 'alert' : 'review',
                title: $leavesABank ? 'Vehicle moved off a bank' : 'Bank assigned to a vehicle',
                summary: 'Vehicle '.$vehicle->plate.' moved from '.$fromLabel.' to '.$toLabel.'.',
                brand: $brand,
                actor: ['type' => $audit->actor_type, 'id' => $audit->actor_id, 'label' => $audit->actor_label],
                subject: $subject,
                data: $data,
                dedupeKey: $leavesABank ? null : 'vehicles:financier:assigned:'.($saccoId ?? 'none').':'.$to,
                windowMinutes: $leavesABank ? 0 : 60,
                auditId: $audit->id,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
