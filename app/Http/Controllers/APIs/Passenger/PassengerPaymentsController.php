<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * A passenger's own payment history.
 *
 * The app's payment-history screen previously had no data source it was
 * allowed to read: the dashboard `transactions` endpoint is gated behind
 * `permission:View Transactions`, so a normal passenger got a 403 and an
 * empty screen. A passenger's payments are not a privileged, cross-SACCO
 * ledger — they are simply THEIR OWN paid bookings, each with the M-Pesa
 * receipt (transid/amount/transdate) recorded on the booking's callback.
 *
 * This endpoint derives exactly that: `Booking where user_id = caller and
 * paid = true`, joined to `mpesa_booking_callbacks` for the receipt. It is
 * strictly scoped to auth()->id() and never reads another user's rows — there
 * is no permission branch that could widen it, unlike the bookings listing.
 */
class PassengerPaymentsController extends Controller
{
    use PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET payments/history — the caller's own payments/receipts, paginated.
     *
     * Optional date range (both inclusive, applied to the booking's
     * created_at): ?from_date=YYYY-MM-DD&to_date=YYYY-MM-DD. Either bound may
     * be given alone. Without a range, all of the caller's paid bookings are
     * returned, newest first.
     */
    public function index(Request $request)
    {
        $perPage = $this->perPage($request, 20);
        $page = max((int) $request->input('page', 1), 1);
        $offset = ($page - 1) * $perPage;

        $query = Booking::query()
            ->with([
                'from',
                'to',
                'queue.vehicle',
                'queue.route.from',
                'queue.route.to',
                'mpesa_booking_callbacks',
            ])
            // The whole point of the endpoint: a passenger's payments are their
            // OWN paid bookings, and nothing else. No permission branch widens
            // this — it is always the authenticated caller's id.
            ->where('user_id', auth()->id())
            ->where('paid', true);

        // Optional inclusive date range on the booking date. `to_date` covers
        // the whole day, so pushing to the start of the next day and using a
        // half-open upper bound avoids dropping same-day rows to a time cutoff.
        if (filled($request->input('from_date'))) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from_date'))->startOfDay());
        }
        if (filled($request->input('to_date'))) {
            $query->where('created_at', '<', Carbon::parse($request->input('to_date'))->addDay()->startOfDay());
        }

        $meta = $this->pageMeta($query, $request, $perPage);

        $bookings = $query
            ->orderBy('created_at', 'DESC')
            ->skip($offset)
            ->take($perPage)
            ->get();

        $payments = $bookings->map(fn (Booking $booking) => $this->toPayment($booking))->values();

        return response()->json(array_merge(['payments' => $payments], $meta));
    }

    /**
     * Flatten a paid booking into a payment/receipt row.
     *
     * The response shape is deliberately stable (not the raw model) so the
     * payment-history screen reads the same keys regardless of how bookings
     * evolve, and so no unrelated booking internals leak.
     */
    private function toPayment(Booking $booking): array
    {
        // Most recent M-Pesa receipt for this booking, if the fare settled via
        // an STK callback. A cash/points fare has none.
        $receipt = $booking->mpesa_booking_callbacks
            ->sortByDesc(fn (MpesaBookingCallback $c) => $c->transdate ?? $c->created_at)
            ->first();

        $from = $booking->from?->name ?? $booking->queue?->route?->from?->name;
        $to = $booking->to?->name ?? $booking->queue?->route?->to?->name;

        return [
            'booking_id' => (int) $booking->id,
            // No dedicated ref column exists; the booking id is the reference
            // the rest of the system already uses (AccountReference on STK).
            'reference' => 'BKG-'.$booking->id,
            'amount' => (float) $booking->amount,
            // Prefer the receipt's transaction time; fall back to when the
            // booking row was last touched (which is when `paid` flipped).
            'date' => optional($receipt?->transdate ? Carbon::parse($receipt->transdate) : $booking->updated_at)->toIso8601String(),
            'method' => $this->method($booking, $receipt),
            'mpesa_receipt_number' => $receipt?->transid,
            'route' => $from && $to ? $from.' - '.$to : null,
            'plate' => $booking->queue?->vehicle?->plate,
            'passengers' => (int) $booking->passengers,
        ];
    }

    /**
     * Normalise the rail to the vocabulary the payment screen renders:
     * mpesa | cash | points | wallet | ncba_till | coop_till.
     *
     * A booking may carry no explicit payment_method (older rows), so a
     * present M-Pesa receipt is treated as authoritative evidence of `mpesa`.
     */
    private function method(Booking $booking, ?MpesaBookingCallback $receipt): ?string
    {
        $method = $booking->payment_method;

        if ($method === PaymentMethod::LoyaltyPoints) {
            return 'points';
        }
        if ($method !== null) {
            return $method->value;
        }

        return $receipt !== null ? 'mpesa' : null;
    }
}
