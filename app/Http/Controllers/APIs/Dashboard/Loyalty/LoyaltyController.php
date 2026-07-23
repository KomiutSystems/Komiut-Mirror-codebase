<?php

namespace App\Http\Controllers\APIs\Dashboard\Loyalty;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\LoyaltyTransaction;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Loyalty
 */
class LoyaltyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Loyalty summary
     *
     * Per-SACCO reward cards for the passenger's loyalty screen — redeemable
     * SACCOs first, then the ones they're closest to a free ride with.
     *
     * @authenticated
     *
     * @response 200 {"loyalty": [{"sacco_id": 1, "sacco": "Nairobi CBD SACCO", "balance": 620, "redemption_threshold": 500, "points_to_reward": 0, "eligible_to_redeem": true, "is_active": true}]}
     */
    public function summary(LoyaltyService $loyalty)
    {
        return response()->json(['loyalty' => $loyalty->summary((int) auth()->id())]);
    }

    /**
     * Points history
     *
     * The passenger's earn/redeem ledger, newest first.
     *
     * @authenticated
     *
     * @queryParam sacco_id integer Filter to one SACCO. Example: 1
     * @queryParam page integer Page number. Example: 1
     */
    public function history(Request $request)
    {
        $transactions = LoyaltyTransaction::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->when($request->sacco_id > 0, fn ($q) => $q->where('sacco_id', (int) $request->sacco_id))
            ->with('sacco:id,name')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['transactions' => $transactions]);
    }

    /**
     * Redeem a free ride
     *
     * Spends the SACCO's redemption threshold in points to settle a reserved
     * (unpaid) booking as paid — a free ride. Fails if the booking isn't yours,
     * is already paid, the SACCO has no active program, or you're short on points.
     *
     * @authenticated
     *
     * @bodyParam booking_id integer required The reserved booking to settle with points. Example: 41
     *
     * @response 200 {"success": "Free ride redeemed!", "booking_id": 41, "points_spent": 500}
     * @response 422 {"error": "You do not have enough points for a free ride."}
     */
    public function redeem(Request $request, LoyaltyService $loyalty)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|integer|min:1|exists:bookings,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $booking = Booking::withoutGlobalScopes()->find($request->booking_id);
        $result = $loyalty->redeemForBooking(auth()->user(), $booking);

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], $result['status']);
        }

        return response()->json([
            'success' => 'Free ride redeemed!',
            'booking_id' => $booking->id,
            'points_spent' => $result['points_spent'],
        ]);
    }
}
