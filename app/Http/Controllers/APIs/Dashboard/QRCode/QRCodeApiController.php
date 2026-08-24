<?php

namespace App\Http\Controllers\APIs\Dashboard\QRCode;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Point;
use App\Models\QrcodePayment;
use App\Models\RedeemedPoint;
use App\Models\SeatArrangement;
use App\Models\Vehicle;
use App\Services\Payments\QrTokenService;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
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

        $vehicle = Vehicle::with('sacco:id,name')->find((int) $claims['vehicle_id']);
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
            return response()->json(['errors' => $validator->messages()], 401);
        }
        $vehicle = Vehicle::with(['seat.seat_arrangements', 'sacco'])->where('till_number', $request->till_number)->first();
        $seat = SeatArrangement::find($request->seat_id);
        $points = Point::where('phone', auth()->user()->phone)->where('sacco_id', $vehicle->sacco_id)->first();
        if ($vehicle == null) {
            return response()->json(['error' => 'Till Number could not be found'], 400);
        }
        return response()->json(['vehicle' => $vehicle, 'seat' => $seat, 'points' => $points]);
    }

    public function getQRCodePayments(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        // 'user' only — this screen prints a payer's name next to an amount. It
        // used to eager-load 'user.roles' and 'user.gender' as well, shipping
        // every payer's RBAC role list and gender to any caller who could see
        // the row. Neither is rendered, and roles are an access-control fact
        // about a person, not payment data.
        $payments = QrcodePayment::with(['vehicle.sacco', 'vehicle.seat', 'user'])
            ->whereBetween('created_at', [$from_date, $to_date]);

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
            $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
            $all_vehicles = [];

            foreach ($vehicles as $vehicle) {
                $v = trim($vehicle);
                if ($v != "") {
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
                    $q->where('plate', LikeSql::op(), '%' . $request->search . '%');
                })->orWhereHas('vehicle.sacco', function ($q) use ($request) {
                    $q->where('name', LikeSql::op(), '%' . $request->search . '%');
                });
            }))->orderBy('created_at', 'DESC');
        $__meta = $this->pageMeta($payments, $request, 20);
        $payments = $payments->skip($offset)->take(20)->get();
        return response()->json(array_merge(['payments' => $payments], $__meta));
    }

    public function redeemPoints(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "vehicle_id" => "required|integer|exists:vehicles,id",
            "seat_id" => "nullable|integer|exists:seat_arrangements,id",
            "user_id" => "nullable|integer|exists:users,id",
        ]);
        if ($validator->fails()) {
            return response()->json(["errors" => $validator->messages()], 400);
        }

        // Redeem the AUTHENTICATED caller's own points — never a client-supplied
        // phone (that let anyone drain another phone number's loyalty balance).
        // Mirrors how getVehicle() (above) and PointsAPIController already scope
        // Point lookups to auth()->user()->phone.
        $points = Point::where('phone', auth()->user()->phone)->first();
        if ($points == null) {
            return response()->json(['error' => 'You do not have enough points to proceed!'], 401);
        }
        if ($points->points < 50) {
            return response()->json(['error' => 'You do not have enough points to proceed!'], 401);
        }
        $redeemedPoint = new RedeemedPoint();
        $redeemedPoint->point_id = $points->id;
        $redeemedPoint->redeemed_points = 50;
        $redeemedPoint->vehicle_id = $request->vehicle_id;
        if ($redeemedPoint->save()) {
            $points->points = $points->points - 50;
            $points->redeemed = $points->redeemed + 50;
            $points->save();
            return response()->json(['success' => 'Points Redeemed successfully']);
        } else {
            return response()->json(['error' => 'Unable to redeem points at the moment!'], 401);
        }
    }
}
