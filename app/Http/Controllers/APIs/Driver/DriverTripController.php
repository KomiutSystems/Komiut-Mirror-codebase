<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Cash;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\SeatBooking;
use App\Models\Transaction;
use App\Support\BusinessDay;
use App\Support\TransDate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use App\Enums\BookingCancellationReason;
use App\Events\BookingCancelled;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @group Driver — the trip and its manifest
 *
 * The write half of a shift. Three things had no endpoint at all, so the app
 * could start a run and then do nothing with it:
 *
 *   - READ the current trip. `trips/start` returned the queue once and nothing
 *     could fetch it again, so the app polled a guaranteed 404 on every load.
 *   - END a trip. The dashboard has queues/complete/queue, but it takes an
 *     arbitrary queue id with NO ownership check and never stamps end_time.
 *   - Take a CASH fare, board a passenger, or record a no-show.
 *
 * Every action resolves its target from the caller's own assignment, so no
 * endpoint here accepts a vehicle or queue id. That is the whole authorization
 * model, and it is why a driver cannot reach another bus's manifest.
 */
class DriverTripController extends Controller
{
    use ResolvesDriverVehicle;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * The trip the driver is on right now
     *
     * Returns null rather than 404 when idle: "between runs" is a normal state
     * for a driver, not an error, and the app should render a Join Queue button
     * rather than an error card.
     */
    public function show(): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $queue = $this->currentQueue((int) $vehicle->id);

        return response()->json(['trip' => $queue === null ? null : $this->payload($queue)]);
    }

    /**
     * End the trip
     *
     * Resolves the queue from the caller's assignment, so the id cannot be
     * supplied — unlike the dashboard's version, which would let a driver close
     * another bus's run. Also stamps end_time, which that one omits, so a
     * completed trip has a duration.
     */
    public function end(Request $request): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $queue = $this->currentQueue((int) $vehicle->id);
        if ($queue === null) {
            return response()->json(['error' => 'You are not currently on a trip.'], 404);
        }

        // A trip that never departed is not a trip. currentQueue() resolves
        // Active OR Pending, so joining a queue and immediately ending it used
        // to mint a Completed row for a bus that never moved -- and completed
        // queues are exactly what the earnings screen and the SACCO's trip
        // reports count. The driver who joined by mistake has a cancel; this
        // path is for arriving.
        if (optional($queue->queue_status)->status !== 'Active') {
            return response()->json([
                'error' => 'You have not departed yet. Depart first, or cancel the queue.',
            ], 409);
        }

        // BEFORE the no-show sweep below, not after it. This lookup used to sit
        // between the sweep and the queue write, so on an environment missing
        // the Completed status every unmarked passenger was refunded, released
        // and told so, and THEN the call 422'd with the trip still Active --
        // money moved for an end that never happened. Every precondition that
        // can refuse the end is checked before anything irreversible runs.
        $completed = QueueStatus::where('status', 'Completed')->first();
        if ($completed === null) {
            return response()->json(['error' => 'No completed status configured.'], 422);
        }

        // A TRIP CANNOT END WITH PAID PASSENGERS IN LIMBO.
        //
        // `confirmed` is the manifest's own word for paid + active + not boarded:
        // someone who paid for a seat and was never marked either way. Ending the
        // trip used to leave every one of them exactly there, forever -- money
        // kept, seat consumed, no refund, no notification -- because end() only
        // ever touched the queue row.
        //
        // The obvious fix, refund them all automatically at trip end, is wrong.
        // "Unmarked" is ambiguous: it is EITHER a no-show the conductor forgot to
        // record OR a passenger who rode and the conductor forgot to tap board.
        // Auto-refunding refunds the second kind too, and it hands a conductor a
        // collusion move -- never tap board, the friend rides AND gets their fare
        // back. So every refund stays an explicit decision. The trip refuses to
        // end until each confirmed passenger is boarded or no-showed, and the
        // response names them so the app can put them in front of the conductor.
        //
        // `unmarked: "no_show"` is the fast path for a conductor at the far
        // terminus who knows nobody left on the list ever turned up: it no-shows
        // each of them through the SAME path the per-passenger tap uses (refund,
        // release, notify, all idempotent) and then ends. Still an explicit act,
        // just one call instead of N.
        $unmarked = Booking::where('queue_id', $queue->id)->statusIs('confirmed')->get();
        $noShows = 0;

        if ($unmarked->isNotEmpty()) {
            if ($request->input('unmarked') !== 'no_show') {
                return response()->json([
                    'error' => sprintf(
                        '%d paid passenger%s %s not been marked as boarded or not boarded. Mark each one, or send unmarked: "no_show" to treat them all as not boarded.',
                        $unmarked->count(), $unmarked->count() === 1 ? '' : 's', $unmarked->count() === 1 ? 'has' : 'have',
                    ),
                    'unmarked' => $unmarked->map(fn (Booking $b) => [
                        'id' => (int) $b->id,
                        'name' => $b->name,
                        'passengers' => (int) $b->passengers,
                        'from_id' => (int) $b->from_id,
                        'payment_method' => $b->payment_method?->value ?? $b->payment_method,
                    ])->values(),
                ], 409);
            }

            // noShow() is a guarded write and says whether it took. A row that
            // was boarded or cancelled between the read above and its UPDATE is
            // skipped -- no seat release, no refund, no notification -- rather
            // than counted as a no-show it was not.
            foreach ($unmarked as $booking) {
                if ($this->noShow($booking)) {
                    $noShows++;
                }
            }
        }

        $queue->queue_status_id = $completed->id;
        $queue->end_time = Carbon::now();
        $queue->save();

        $this->forgetTakings((int) $vehicle->id);

        return response()->json([
            'success' => 'Trip ended.',
            'no_shows' => $noShows,
            'trip' => $this->payload($queue->fresh()->load(['route.from', 'route.to', 'terminus.place', 'queue_status'])),
        ]);
    }

    /**
     * Confirm a cash fare
     *
     * A conductor takes notes at the door and there was nowhere to record it,
     * so the booking stayed unpaid and CheckPassengerPayments cancelled it two
     * minutes later — releasing a seat the passenger was already sitting in.
     *
     * Writes the `cashes` row AND its `transactions` row, so the fare reaches
     * the takings and the SACCO summaries by the same path an M-Pesa payment
     * takes. Flipping `paid` alone would leave the money invisible.
     */
    public function confirmCash(Request $request, int $booking): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $row = $this->ownBooking($vehicle, $booking);
        if ($row === null) {
            return response()->json(['error' => 'That booking is not on your vehicle.'], 404);
        }
        if ($row->paid) {
            return response()->json(['error' => 'That booking is already paid.'], 409);
        }

        // The booked fare is a FLOOR, not merely a default.
        //
        // `amount` used to be taken as given behind nothing but a > 0 check, so a
        // driver could mark a 500/= booking paid with amount=1. The passenger
        // rides, the booking reads PAID, the SACCO's takings read 1/=, and the
        // system's own records corroborate the driver — the exact leak this
        // product exists to close. It was reachable from a token issued on a
        // phone number and a plate painted on the side of the bus.
        //
        // Above the fare is legitimate (luggage, a round-up) and is recorded as
        // given. Below it is not.
        $raw = $request->input('amount');
        if ($raw !== null && ! is_numeric($raw)) {
            return response()->json(['errors' => ['amount' => ['A cash fare must be a number.']]], 400);
        }

        $fare = (float) $row->amount;
        $amount = $raw !== null ? (float) $raw : $fare;

        if ($amount <= 0) {
            return response()->json(['errors' => ['amount' => ['A cash fare must be greater than zero.']]], 400);
        }

        // Tolerance covers float representation only, not a discount.
        if ($amount < $fare - 0.001) {
            return response()->json(['errors' => ['amount' => [
                'A cash fare cannot be less than the booked fare of '.number_format($fare, 2).'.',
            ]]], 422);
        }

        $transaction = DB::transaction(function () use ($row, $vehicle, $amount) {
            $cash = Cash::create([
                // Derived from the booking, and cashes.trans_id is UNIQUE, so a
                // double tap on a flaky matatu connection cannot bank the same
                // fare twice.
                'trans_id' => 'CASH-'.$row->id,
                'vehicle_id' => $vehicle->id,
                'user_id' => $row->user_id,
                'from_id' => $row->from_id,
                'to_id' => $row->to_id,
                'firstname' => $row->name,
                'phone' => $row->phone,
                'passengers' => $row->passengers,
                'recieved_amount' => $amount,
                'fare_amount' => $amount,
                'luggage_amount' => 0,
                'total_amount' => $amount,
                'change_amount' => 0,
                'trans_date' => Carbon::now(),
            ]);

            $created = Transaction::create([
                'vehicle_id' => $vehicle->id,
                'cash_id' => $cash->id,
                'amount' => $amount,
                'trans_date' => Carbon::now(),
            ]);

            $row->update(['paid' => true, 'payment_method' => PaymentMethod::Cash]);

            return $created;
        });

        $this->forgetTakings((int) $vehicle->id);

        return response()->json([
            'success' => 'Cash fare recorded.',
            'booking' => ['id' => (int) $row->id, 'status' => $row->fresh()->status_label],
            'transaction_id' => (int) $transaction->id,
        ], 201);
    }

    /**
     * Board a passenger, or record a no-show
     *
     * A no-show cancels the booking and releases the seat — the same end state
     * the unpaid sweep produces, reached deliberately rather than by timeout,
     * so the conductor can resell the seat now instead of waiting two minutes.
     *
     * THE TWO MARKS ARE TERMINAL AND MUTUALLY EXCLUSIVE. Neither arm checked
     * where the booking already was, and since a no-show REFUNDS, that made
     * the endpoint a money machine in both directions:
     *
     *   board -> no_show   passenger pays, rides, and at the terminus one tap
     *                      cancels the booking and puts the fare back on their
     *                      balance. Rode AND refunded.
     *   no_show -> board   the refund is already on their balance; the board
     *                      then seats them on a cancelled booking (status=0,
     *                      boarded=1) whose seat is back on sale. Rides on the
     *                      refund, and the seat is sold twice.
     *
     * So: a boarded passenger cannot be no-showed, a cancelled booking cannot
     * be boarded, and repeating the SAME mark is a 200 that does nothing --
     * a double tap on a bad connection is a retry, not a second event.
     */
    public function markBooking(Request $request, int $booking): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $row = $this->ownBooking($vehicle, $booking);
        if ($row === null) {
            return response()->json(['error' => 'That booking is not on your vehicle.'], 404);
        }

        $action = (string) $request->input('action', 'board');
        if (! in_array($action, ['board', 'no_show'], true)) {
            return response()->json(['errors' => ['action' => ['Use board or no_show.']]], 400);
        }

        if ($action === 'board') {
            if ($refusal = $this->boardRefusal($row)) {
                return $refusal;
            }

            // Guarded UPDATE, same as noShow(): the checks above read a row that
            // may have changed by the time this writes, and a board that lands
            // on a booking no-showed a moment earlier is the exact no_show ->
            // board hole with a smaller window. Zero rows affected means the
            // row moved; answer for where it is now.
            $affected = Booking::whereKey($row->id)
                ->where('status', true)->where('paid', true)->where('boarded', false)
                ->update(['boarded' => true, 'start_time' => Carbon::now(), 'updated_at' => now()]);

            if ($affected === 0) {
                return $this->boardRefusal($row->refresh())
                    ?? response()->json(['error' => 'This booking changed while you were marking it. Refresh and try again.'], 409);
            }
        } else {
            if ($row->boarded) {
                return response()->json(['error' => 'This passenger is already boarded.'], 409);
            }
            // Already cancelled (a previous no-show, the sweep, the passenger).
            // Idempotent: NOT via noShow(), which would announce it again.
            if (! $row->status) {
                return $this->alreadyMarked($row);
            }

            if (! $this->noShow($row)) {
                // Boarded or cancelled between the read above and the guarded
                // write. Nothing happened; say what the row is now.
                $row->refresh();

                return $row->boarded
                    ? response()->json(['error' => 'This passenger is already boarded.'], 409)
                    : $this->alreadyMarked($row);
            }
        }

        return response()->json([
            'success' => $action === 'board' ? 'Passenger boarded.' : 'Marked as a no-show.',
            'booking' => ['id' => (int) $row->id, 'status' => $row->fresh()->status_label],
        ]);
    }

    /**
     * Why this booking cannot be boarded right now, or null if it can.
     *
     * Paid is required, not just live. The dashboard's stop sweep boards
     * "PAID and ACTIVE only" for the same reason; and an unpaid booking that
     * IS boarded is still an unpaid booking to ReleaseExpiredBookings, which
     * would cancel it and release the seat from under a seated passenger.
     * The fare comes first, through confirmCash or a payment callback.
     */
    private function boardRefusal(Booking $row): ?JsonResponse
    {
        if (! $row->status) {
            return response()->json(['error' => 'This booking was cancelled; the passenger must book again.'], 409);
        }
        if (! $row->paid) {
            return response()->json(['error' => 'Take the fare first.'], 409);
        }
        if ($row->boarded) {
            return response()->json([
                'success' => 'Already boarded.',
                'booking' => ['id' => (int) $row->id, 'status' => $row->status_label],
            ]);
        }

        return null;
    }

    private function alreadyMarked(Booking $row): JsonResponse
    {
        return response()->json([
            'success' => 'Already marked.',
            'booking' => ['id' => (int) $row->id, 'status' => $row->status_label],
        ]);
    }

    /**
     * Not boarded: release the seat, refund what was paid, tell the passenger.
     *
     * ONE implementation, reached from the per-passenger tap and from ending a
     * trip with unmarked passengers. Two copies of the rule that decides whether
     * a passenger gets their money back would be free to drift apart.
     *
     * Returns whether it took. False means the booking was not live-and-unboarded
     * at the moment of the write -- already cancelled, or boarded -- and NOTHING
     * was done: no seat release, no refund, no notification.
     */
    private function noShow(Booking $row): bool
    {
        // QUERY BUILDER, NOT $row->update(). Booking::booted() dispatches
        // BookingCancelled(reason: Cancelled) whenever an Eloquent save flips
        // status to false, and this method dispatches its own BookingCancelled
        // with the NoShow reason below -- so an Eloquent update here announced
        // every no-show TWICE, once as "Booking cancelled" and once as "You were
        // not boarded". Bypassing the model event is the pattern both expiry
        // sweeps already use for exactly this reason (see the note at the top of
        // Booking::booted): cancel by query, then announce by hand with the right
        // reason.
        //
        // CONDITIONAL, and everything below hangs off it. This used to cancel
        // unconditionally, so a boarded passenger -- one who paid and RODE --
        // could be no-showed at the terminus and refunded the fare for the ride
        // they had just taken. The WHERE is the state guard: only a live,
        // unboarded booking can become a no-show, and the row count is the one
        // answer to "did that happen" that a concurrent board or cancel cannot
        // fake. Zero rows means stop here: no release, no refund, no event.
        $affected = Booking::whereKey($row->id)
            ->where('status', true)
            ->where('boarded', false)
            ->update([
                'status' => false,
                'cancellation_reason' => BookingCancellationReason::NoShow->value,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return false;
        }

        SeatBooking::where('booking_id', $row->id)->update(['status' => false]);

        // NOT BOARDED MEANS REFUNDED. A passenger who paid for a seat and was
        // not put on the bus gets it back -- their points if they paid in
        // points, a ride credit in points if they paid money. Without this,
        // one tap here destroyed something the passenger had already bought,
        // and there was no other path in the codebase to give it back.
        //
        // Idempotent at the ledger, so a second tap or a retried request
        // cannot refund twice. Wrapped because the seat is already released
        // above and a refund failure must not report the no-show as failed;
        // the ledger's absence of a 'refunded' row is what a repair would key on.
        //
        // The RESULT is kept, because it is not always a refund. refundForBooking
        // returns null for an unpaid booking, for a SACCO with no loyalty program
        // to price a ride credit at, and (via the catch) for a ledger failure.
        // The notification below is worded off this, so the passenger is never
        // told their money is back when their balance has not moved.
        $refund = null;
        try {
            $refund = app(LoyaltyService::class)->refundForBooking($row);
        } catch (\Throwable $e) {
            report($e);
        }

        // Tell the passenger. This used to be silent -- the only cancellation
        // path with no event -- so a paid passenger learned they had lost the
        // seat by opening the app to an empty screen.
        BookingCancelled::dispatch(
            $row->fresh(),
            BookingCancellationReason::NoShow,
            $refund === null ? null : (float) $refund->value,
        );

        return true;
    }

    /**
     * Keep the home screen's 30s cache honest after a write.
     *
     * The date MUST be the business day, because that is what
     * DriverPortalController keys the entry on when it writes it. This built
     * the key from Carbon::today() instead.
     *
     * THOSE TWO AGREE TODAY, and only by coincidence. The business day starts
     * at 03:00 Africa/Nairobi, which is exactly 00:00 UTC, and the app runs
     * UTC — so Carbon::today() lands on the same boundary at every hour.
     * Checked across the midnight-to-03:00 window that would otherwise be the
     * risky one: identical every time.
     *
     * It is aligned anyway because the coincidence is load-bearing and
     * invisible. BusinessDay's own docblock warns about exactly this: the
     * moment config('app.timezone') moves off UTC, this key silently stops
     * matching the one the portal writes, and a driver ending a shift would
     * clear a key nobody had written while the stale entry stood. Two places
     * deriving the same cache key by different routes is a bug waiting for a
     * config change, not a bug today.
     */
    private function forgetTakings(int $vehicleId): void
    {
        Cache::forget('driver:takings:'.$vehicleId.':'.BusinessDay::current()->toDateString());
    }

    /** @return array<string,mixed> */
    private function payload(Queue $queue): array
    {
        return [
            'queue_id' => (int) $queue->id,
            'queue_number' => $queue->queue_number,
            'status' => optional($queue->queue_status)->status,
            'route' => optional($queue->route)->name,
            'from' => optional(optional($queue->route)->from)->name,
            'to' => optional(optional($queue->route)->to)->name,
            'terminus' => optional(optional($queue->terminus)->place)->name,
            'fare' => (float) $queue->amount,
            // TransDate, not optional(): queues.start_time is not cast on the
            // model, so optional() on a plain string returns null for every one
            // of these -- the same silent hole that left every driver payment
            // with "at": null.
            'started_at' => TransDate::iso($queue->start_time),
            'departed_at' => TransDate::iso($queue->departed_at),
            'ended_at' => TransDate::iso($queue->end_time),
        ];
    }
}
