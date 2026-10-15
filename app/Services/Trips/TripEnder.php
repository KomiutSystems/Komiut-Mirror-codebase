<?php

declare(strict_types=1);

namespace App\Services\Trips;

use App\Enums\BookingCancellationReason;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Services\Booking\BookingCancellation;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ending a bus's trip: the driver's button (DriverTripController::end) and the
 * dashboard's (QueuesAPIController::endTrip) both come here, so the rules below
 * hold whoever presses it. $queue is the trip being ended -- the bus's live
 * run, or the stage queue it departed from when it never went live -- and
 * $run / $stage are the bus's open live run and stage queue.
 */
final class TripEnder
{
    public function end(Queue $queue, ?Queue $run, ?Queue $stage, bool $noShowUnmarked, ?int $endedBy): TripEndResult
    {
        // A trip that never departed is not a trip. A stage queue is Pending
        // until the bus departs, so joining a queue and immediately ending it
        // used to mint a Completed row for a bus that never moved -- and
        // completed queues are exactly what the earnings screen and the SACCO's
        // trip reports count. The driver who joined by mistake has a cancel;
        // this path is for arriving. A live run is Active from the start.
        if (optional($queue->queue_status)->status !== 'Active') {
            return TripEndResult::refused(409, ['error' => 'You have not departed yet. Depart first, or cancel the queue.', 'reason' => 'not_departed']);
        }

        // BEFORE the no-show sweep below, not after it. This lookup used to sit
        // between the sweep and the queue write, so on an environment missing
        // the Completed status every unmarked passenger was refunded, released
        // and told so, and THEN the call 422'd with the trip still Active --
        // money moved for an end that never happened. Every precondition that
        // can refuse the end is checked before anything irreversible runs.
        $completed = QueueStatus::where('status', 'Completed')->first();
        if ($completed === null) {
            return TripEndResult::refused(422, ['error' => 'No completed status configured.']);
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
            if (! $noShowUnmarked) {
                return TripEndResult::refused(409, [
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
                    ])->values()->all(),
                ]);
            }

            // noShow() is a guarded write and says whether it took. A row that
            // was boarded or cancelled between the read above and its UPDATE is
            // skipped -- no seat release, no refund, no notification -- rather
            // than counted as a no-show it was not.
            foreach ($unmarked as $booking) {
                if (app(BookingCancellation::class)->notBoarded($booking, BookingCancellationReason::NoShow)) {
                    $noShows++;
                }
            }
        }

        $endedAt = Carbon::now();

        $queue->queue_status_id = $completed->id;
        $queue->end_time = $endedAt;
        $queue->ended_by = $endedBy;
        $queue->save();

        // The stage queue this bus departed from ends with the run, stamped
        // with the SAME end_time: that pairing is what makes the two rows count
        // as one trip (Queue::whereCountsAsTrip). Only a departed one -- a bus
        // still waiting in a line keeps its place -- and never one carrying
        // paid, unmarked passengers from before stage queues stopped taking
        // bookings; ending that is its own decision, made on its own end.
        if ($run !== null && $stage !== null
            && optional($stage->queue_status)->status === 'Active'
            && ! Booking::where('queue_id', $stage->id)->statusIs('confirmed')->exists()) {
            $stage->queue_status_id = $completed->id;
            $stage->end_time = $endedAt;
            $stage->ended_by = $endedBy;
            $stage->save();
        }

        // Keep the driver home screen's 30s takings cache honest; the key is
        // the business day's, as DriverPortalController writes it.
        Cache::forget('driver:takings:'.$queue->vehicle_id.':'.BusinessDay::current()->toDateString());

        return TripEndResult::ended($queue, $noShows);

    }
}
