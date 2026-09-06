<?php

declare(strict_types=1);

namespace App\Services\Queues;

use App\Models\Queue;
use App\Models\QueueStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The live FIFO line at one terminus, for one route, on one day.
 *
 * A matatu joins the back of the line, waits its turn, and departs from the
 * front. When it pulls out it is no longer in the line, and everyone behind
 * moves up — position 3 becomes position 2. That is what a queue at a stage IS,
 * and it is what the /queues screen has to show.
 *
 * IT DID NOT WORK THAT WAY. Positions were handed out as `max(position) + 1`
 * over every row created that day, whatever its status, and nothing ever gave a
 * position back. So a bus that departed at 06:30 still held slot 1 at midnight,
 * the bus behind it stayed slot 2 forever, and the numbers on the screen were a
 * record of arrival order rather than a queue.
 *
 * THE UNIQUE INDEX IS WHY RELEASING MATTERS, not just tidiness.
 * `queues_terminus_route_day_position_unique` covers
 * (terminus_id, route_id, created_at::date, position), so a departed bus holding
 * slot 1 makes slot 1 unusable for the rest of the day. The vehicle behind it
 * cannot be renumbered into the slot even if we wanted to. So leaving the line
 * releases the position to NULL — Postgres permits many NULLs in a unique index,
 * which is exactly the "not in the line" state we need.
 *
 * RESEQUENCING IS DONE ASCENDING, ONE ROW AT A TIME, and that is deliberate
 * rather than lazy. The index is a plain unique index, not a deferrable
 * constraint, so it is enforced per row inside a statement: a single UPDATE
 * renumbering 2→1, 3→2 can collide with a row it has not moved yet. Walking up
 * the line means every target slot has already been vacated by the row before
 * it, so no intermediate state ever violates.
 *
 * Everything runs under the same advisory lock the join path already used, keyed
 * on terminus+route+day, so a bus joining the back cannot interleave with the
 * line compacting at the front and take a slot that is about to be reassigned.
 */
final class StageLine
{
    /**
     * Statuses that occupy a slot.
     *
     * Only Pending. Active means departed — on the road, not at the stage — so
     * an Active queue holds no place in the line even though it is very much a
     * live trip.
     */
    private const WAITING = ['Pending'];

    /**
     * The next free slot at the back of the line.
     *
     * Counts only WAITING rows, so slots released by departures are reused
     * rather than skipped: a stage that has seen forty buses all day still hands
     * the forty-first "3" if only two are actually waiting.
     */
    public function takeSlot(int $terminusId, int $routeId, ?string $day = null): int
    {
        $day ??= Carbon::today()->toDateString();
        $this->lock($terminusId, $routeId, $day);

        return (int) $this->waiting($terminusId, $routeId, $day)->max('position') + 1;
    }

    /**
     * Take a vehicle out of the line and close the gap behind it.
     *
     * Safe to call for a queue that holds no slot — a departure whose position
     * was already released, or a row from before this existed — so callers do
     * not have to know.
     */
    public function release(Queue $queue): void
    {
        $terminusId = (int) $queue->terminus_id;
        $routeId = (int) $queue->route_id;
        $day = Carbon::parse($queue->created_at ?? Carbon::now())->toDateString();

        DB::transaction(function () use ($queue, $terminusId, $routeId, $day): void {
            $this->lock($terminusId, $routeId, $day);

            if ($queue->position !== null) {
                $queue->position = null;
                $queue->save();
            }

            $this->compact($terminusId, $routeId, $day);
        });
    }

    /**
     * Renumber the waiting line 1..N, preserving its order.
     *
     * Ordered by position first so the line keeps the sequence it already had,
     * then by id so a row whose position is NULL (released, or never assigned)
     * lands deterministically at the back rather than wherever the planner felt
     * like putting it.
     */
    public function compact(int $terminusId, int $routeId, ?string $day = null): int
    {
        $day ??= Carbon::today()->toDateString();

        $waiting = $this->waiting($terminusId, $routeId, $day)
            ->orderByRaw('position IS NULL, position')
            ->orderBy('id')
            ->get();

        $slot = 0;
        $moved = 0;

        foreach ($waiting as $row) {
            $slot++;

            if ((int) $row->position === $slot) {
                continue;
            }

            $row->position = $slot;
            $row->queue_number = 'QN-'.$slot;
            $row->save();
            $moved++;
        }

        return $moved;
    }

    /** @return Builder<Queue> */
    private function waiting(int $terminusId, int $routeId, string $day)
    {
        return Queue::withoutGlobalScopes()
            ->whereIn('queue_status_id', QueueStatus::whereIn('status', self::WAITING)->pluck('id'))
            ->where('terminus_id', $terminusId)
            ->where('route_id', $routeId)
            ->whereDate('created_at', $day);
    }

    /**
     * Serialise everything touching one line.
     *
     * Advisory rather than row locks because the thing being protected is the
     * SHAPE of the line, not any single row: two drivers joining at once must
     * not compute the same slot, and a join must not read a line that is
     * halfway through compacting. Transaction-scoped, so it releases on commit
     * or rollback without a cleanup path.
     */
    private function lock(int $terminusId, int $routeId, string $day): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(?)', [
            (int) crc32("queue-slot:{$terminusId}:{$routeId}:{$day}"),
        ]);
    }
}
