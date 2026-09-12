<?php

namespace App\Http\Controllers\APIs\Dashboard\QRCode;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\QrcodePayment;
use App\Models\SeatArrangement;
use App\Models\Vehicle;
use App\Services\Payments\QrTokenService;
use App\Services\Sql\LikeSql;
use App\Services\Sql\PlateSql;
use Carbon\Carbon;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * @group QR-code fare payment
 *
 * Pay a matatu by scanning its printed QR. The QR holds a tamper-proof SIGNED
 * token (see QrTokenService) that encodes only the vehicle identity — never the
 * amount, because fares vary. Flow: the SACCO/driver generates the token
 * (vehicle/{id}/token), the passenger scans and resolves it (qrcode/resolve),
 * then pays the resolved vehicle's till via the QR STK push (qrcode/stk/push)
 * with a passenger-entered amount.
 */
class QRCodeApiController extends Controller
{
    use PaginatesResults;
    use ScopesToOwnedVehicles;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Generate a vehicle's fare QR token
     *
     * Returns a tamper-proof signed token for the vehicle; the dashboard/app
     * renders a QR from it and displays it in the matatu. Never expires; encodes
     * only the vehicle identity, never an amount.
     *
     * @authenticated
     *
     * @urlParam vehicle integer required The vehicle id. Example: 1
     *
     * @response 200 {"token": "eyJ2ZWhpY2xlX2lkIjoxfQ.f3a9...", "vehicle": {"id": 1, "plate": "KDA123X", "till_number": "5202020"}}
     */
    public function vehicleToken(Vehicle $vehicle, QrTokenService $qr)
    {
        $token = $qr->generate([
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $vehicle->sacco_id,
            'plate' => $vehicle->plate,
        ]);

        return response()->json([
            'token' => $token,
            'vehicle' => ['id' => $vehicle->id, 'plate' => $vehicle->plate, 'till_number' => $vehicle->till_number],
        ]);
    }

    /**
     * Resolve a scanned fare QR
     *
     * Validates the signed token from a scanned QR and returns the vehicle to
     * pay (plate + till + SACCO). Tampered/forged tokens are rejected. The amount
     * is not in the QR — the passenger enters it, then calls the QR STK push.
     *
     * @authenticated
     *
     * @bodyParam token string required The scanned QR token. Example: eyJ2ZWhpY2xlX2lkIjoxfQ.f3a9...
     *
     * @response 200 {"vehicle": {"id": 1, "plate": "KDA123X", "till_number": "5202020", "sacco": {"id": 2, "name": "Umoja SACCO"}}}
     * @response 422 {"error": "Invalid or tampered QR code"}
     */
    public function resolveToken(Request $request, QrTokenService $qr)
    {
        $data = Validator::make($request->all(), ['token' => 'required|string'])->validate();

        $claims = $qr->validate($data['token']);
        if (! $claims || empty($claims['vehicle_id'])) {
            return response()->json(['error' => 'Invalid or tampered QR code'], 422);
        }

        // The SACCO name is loaded WITHOUT scopes; the vehicle lookup keeps
        // them. Those are different questions: whether this passenger may see
        // this bus is a brand decision and stays scoped, but once the bus is
        // theirs to see, naming its SACCO is not. BrandScope applies to a
        // passenger (no sacco_id, so boundedBySomethingTighter is false) and
        // filters saccos.brand -- so the eager load returned NULL and the
        // passenger scanned a real bus belonging to nobody. Same leak as
        // LoyaltyController::history.
        $vehicle = Vehicle::with(['sacco' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name')])
            ->find((int) $claims['vehicle_id']);
        if (! $vehicle) {
            return response()->json(['error' => 'Vehicle not found'], 404);
        }

        return response()->json([
            'vehicle' => [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'till_number' => $vehicle->till_number,
                'sacco' => $vehicle->sacco,
            ],
        ]);
    }

    public function getVehicle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'till_number' => 'required|numeric',
            'seat_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            // 400, NOT 401. This answered a missing or non-numeric till_number
            // with 401, and 401 means "your session is invalid" to every HTTP
            // client on the platform -- the app's interceptor signs the user out
            // on it. So a passenger who fat-fingered one digit of the till
            // printed in the matatu was logged out of the app, at the door, with
            // the conductor waiting. This is the first call of the QR payment
            // flow, so that landed on the money path. 401 is for auth only.
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $vehicle = Vehicle::with(['seat.seat_arrangements', 'sacco'])->where('till_number', $request->till_number)->first();

        // The null check has to come FIRST. It used to sit below the Point
        // lookup, which reads $vehicle->sacco_id — so a passenger who mistyped
        // one digit of the till printed in the matatu got "Server Error" off a
        // null dereference, and the 404 written for exactly that case was
        // unreachable. This is the first step of the QR payment flow, so the
        // failure landed on the money path.
        if ($vehicle == null) {
            return response()->json([
                'message' => 'No matatu is registered to that till number. Check the number displayed in the matatu and try again.',
                'error' => 'No matatu is registered to that till number. Check the number displayed in the matatu and try again.',
            ], 404);
        }

        $seat = SeatArrangement::find($request->seat_id);

        // `loyalty` is what decides whether this screen may OFFER "pay with
        // points" for the bus in front of the passenger, so it has to be the
        // balance they actually hold. It replaces a lookup against the legacy
        // `points` table, which is keyed on phone, has been empty since the
        // per-SACCO rewrite, and therefore told every passenger they had
        // nothing -- while qrcode/redeem_points happily spent from the real
        // balance. The screen and the payment disagreed about the same money.
        //
        // `points` is kept and always null ONLY so an older client reading the
        // key does not crash on its absence. It carried null in practice
        // anyway. New clients read `loyalty`.
        $loyalty = $vehicle->sacco_id === null
            ? null
            : app(LoyaltyService::class)->cardForSacco((int) auth()->id(), (int) $vehicle->sacco_id);

        return response()->json([
            'vehicle' => $vehicle,
            'seat' => $seat,
            'loyalty' => $loyalty,
            'points' => null,
        ]);
    }

    public function getQRCodePayments(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != '' ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        // 'user' only — this screen prints a payer's name next to an amount. It
        // used to eager-load 'user.roles' and 'user.gender' as well, shipping
        // every payer's RBAC role list and gender to any caller who could see
        // the row. Neither is rendered, and roles are an access-control fact
        // about a person, not payment data.
        // mpesa_qrcode_payment carries the two columns this screen leads with and
        // qrcode_payments does not have: the M-Pesa receipt number and the payer's
        // phone. Without it Reference and Phone render blank — which nobody would
        // have noticed until the first real QR payment, because the table is empty.
        //
        // It is also the difference between one query and N+1: this listing pages
        // 20 rows, so a lazy relation is 20 extra round trips per page.
        $payments = QrcodePayment::with([
            'vehicle.sacco',
            'vehicle.seat',
            'user',
            'mpesa_qrcode_payment',
        ])->whereBetween('created_at', [$from_date, $to_date]);

        // The permission WIDENS the view; it does not remove a restriction.
        //
        // This was inverted: `if (! can('View Transactions')) { own rows }` with
        // no else-branch constraint, so HOLDING the permission dropped the
        // own-rows filter and returned every QR payment the query could reach —
        // more permission produced more data by negation rather than by an
        // explicit grant. QrcodePayment now carries SaccoScope/BrandScope (via
        // its vehicle), so the widened set is the caller's own SACCO. But
        // SaccoScope does NOT apply to a user with no home SACCO, so that case
        // is failed closed here: a saccoless non-super caller (a passenger, a
        // driver) has no tenant to widen to and only ever sees payments they
        // made themselves. A superadmin is saccoless by design and stays
        // unconstrained.
        $caller = auth()->user();
        $widened = $caller->can('View Transactions')
            && ($caller->isSuperAdmin() || $caller->currentSaccoId() !== null);

        if (! $widened) {
            $payments = $payments->where('user_id', Auth::user()->id);
        }

        // Filters narrow the set the branch above settled on; they never widen
        // it. ?sacco used to be applied BEFORE the ownership branch, which for a
        // caller restricted to their own rows was harmless but for a saccoless
        // caller read as a way to pick a SACCO — it stays after the branch, and
        // for a scoped caller SaccoScope already bounds it.
        if ($request->sacco > 0) {
            $payments = $payments->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }

        if ($widened) {
            // The widened set is the caller's whole SACCO — for an investor that
            // is ~147 buses they have no stake in. Narrow it to the ones they
            // own before the ?vehicles picker below, which can only narrow
            // further. Nothing is added on the un-widened path: that branch
            // already pins the caller to payments they made themselves, which is
            // tighter than ownership.
            //
            // Ungated: an empty array compiles to `0 = 1`, so an investor with
            // no open assignment sees nothing.
            $ownedVehicleIds = $this->ownedVehicleIds();
            if ($ownedVehicleIds !== null) {
                $payments->whereIn('vehicle_id', $ownedVehicleIds);
            }

            $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
            $all_vehicles = [];

            foreach ($vehicles as $vehicle) {
                $v = trim($vehicle);
                if ($v != '') {
                    array_push($all_vehicles, trim($vehicle));
                }
            }
            if (count($all_vehicles) > 0) {
                $payments->whereIn('vehicle_id', $all_vehicles);
            }
        }
        // when() wraps the whole group: with an empty box this is two correlated
        // EXISTS subqueries into vehicles and saccos matching every row.
        $payments = $payments->when(filled($request->search), fn ($builder) => $builder
            ->where(function ($query) use ($request) {
                $query->orWhereHas('vehicle', function ($q) use ($request) {
                    $q->whereRaw(PlateSql::matchSql('plate'), [PlateSql::matchBinding((string) $request->search)]);
                })->orWhereHas('vehicle.sacco', function ($q) use ($request) {
                    $q->where('name', LikeSql::op(), '%'.$request->search.'%');
                });
            }))->orderBy('created_at', 'DESC');
        $__meta = $this->pageMeta($payments, $request, 20);
        $payments = $payments->skip($offset)->take(20)->get();

        // ADDITIVE. The rows keep the shape the dashboard already renders —
        // reshaping them broke two tests that pin this contract, which is the
        // contract the screen is built against. These two fields are simply the
        // ones it was missing: `reference` is the M-Pesa receipt a person quotes
        // when a payment is disputed, and `phone` is who paid. Both live on the
        // linked mpesa_qrcode_payments row, and both are null until the money
        // actually lands — which is what the Status column is for.
        $payments->each(function (QrcodePayment $p): void {
            $settled = $p->mpesa_qrcode_payment;

            $p->setAttribute('reference', $settled?->transid);
            $p->setAttribute('phone', $settled?->phone);
        });

        return response()->json(array_merge(['payments' => $payments], $__meta));
    }

    /**
     * Pay for a ride with points by scanning the bus
     *
     * Spends the fare you name, priced at the vehicle's SACCO's point value, from
     * your balance with that SACCO, and writes a `qrcode_payments` receipt — the
     * same artefact an M-Pesa QR payment writes. NO QUEUE AND NO BOOKING ARE
     * INVOLVED, which is the point: a bus that has left the stage can still be
     * paid for. A scan identifies a bus, not a journey, so the fare is the one
     * the conductor asked for, exactly as it is for qrcode/stk/push.
     *
     * REPEATING THE CALL IS SAFE. A repeat within ten minutes returns the first
     * redemption and takes no further points, because the QR a passenger scans is
     * printed and static and nothing in the request can tell a retry from a new
     * ride. Beyond that it is treated as a new boarding and costs again.
     *
     * @authenticated
     *
     * @bodyParam vehicle_id integer required The scanned vehicle. Example: 750
     * @bodyParam amount number required The fare in KES, as the conductor asked for it. Example: 60
     * @bodyParam seat_id integer The seat_arrangement id, if the passenger picked one. Example: 12
     *
     * @response 200 {"success": "Ride paid with points.", "points_spent": 20, "fare": 60, "balance": 30, "payment_id": 9, "replay": false}
     * @response 422 {"error": "This ride costs 20 points and you have 12.", "points_needed": 20}
     */
    public function redeemPoints(Request $request, LoyaltyService $loyalty)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            // The fare, in KES, as the conductor asked for it -- exactly what
            // qrcode/stk/push takes. A scan identifies a bus, not a journey, so
            // there is nothing to price from but what the passenger was told;
            // the points cost is this at the SACCO's point_value.
            'amount' => 'required|numeric|min:1|max:100000',
            'seat_id' => 'nullable|integer|exists:seat_arrangements,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Scopes dropped for the same reason every passenger-facing vehicle read
        // drops them: a passenger has no sacco_id, SaccoScope fails closed on
        // that, and the bus they just scanned is one they are standing next to.
        $vehicle = Vehicle::withoutGlobalScopes()->find((int) $request->vehicle_id);
        if ($vehicle === null) {
            return response()->json(['error' => 'Vehicle not found'], 404);
        }

        // ALWAYS the authenticated caller's own points. The previous version
        // looked the balance up by auth()->user()->phone against the legacy
        // `points` table; an earlier one took a client-supplied phone, which let
        // anyone drain another number's balance.
        $result = $loyalty->redeemForVehicle(
            auth()->user(),
            $vehicle,
            (float) $request->amount,
            $request->filled('seat_id') ? (int) $request->seat_id : null,
        );

        if (! $result['ok']) {
            return response()->json(
                array_filter(['error' => $result['error'], 'points_needed' => $result['points_needed'] ?? null], fn ($v) => $v !== null),
                $result['status'],
            );
        }

        return response()->json([
            'success' => 'Ride paid with points.',
            'points_spent' => $result['points_spent'],
            'fare' => (float) $request->amount,
            'balance' => $result['balance'],
            'payment_id' => $result['payment']->id ?? null,
            'replay' => (bool) ($result['replay'] ?? false),
        ]);
    }
}
