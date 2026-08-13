<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use App\Models\Cash;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\SeatBooking;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
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
    public function end(): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $queue = $this->currentQueue((int) $vehicle->id);
        if ($queue === null) {
            return response()->json(['error' => 'You are not currently on a trip.'], 404);
        }

        $completed = QueueStatus::where('status', 'Completed')->first();
        if ($completed === null) {
            return response()->json(['error' => 'No completed status configured.'], 422);
        }

        $queue->queue_status_id = $completed->id;
        $queue->end_time = Carbon::now();
        $queue->save();

        $this->forgetTakings((int) $vehicle->id);

        return response()->json([
            'success' => 'Trip ended.',
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

        $amount = (float) ($request->input('amount') ?? $row->amount);
        if ($amount <= 0) {
            return response()->json(['errors' => ['amount' => ['A cash fare must be greater than zero.']]], 400);
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
            $row->update(['boarded' => true, 'start_time' => Carbon::now()]);
        } else {
            $row->update(['status' => false]);
            SeatBooking::where('booking_id', $row->id)->update(['status' => false]);
        }

        return response()->json([
            'success' => $action === 'board' ? 'Passenger boarded.' : 'Marked as a no-show.',
            'booking' => ['id' => (int) $row->id, 'status' => $row->fresh()->status_label],
        ]);
    }

    /** Keep the home screen's 30s cache honest after a write. */
    private function forgetTakings(int $vehicleId): void
    {
        Cache::forget('driver:takings:'.$vehicleId.':'.Carbon::today()->toDateString());
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
            'started_at' => optional($queue->start_time)->toIso8601String(),
            'ended_at' => optional($queue->end_time)->toIso8601String(),
        ];
    }
}
