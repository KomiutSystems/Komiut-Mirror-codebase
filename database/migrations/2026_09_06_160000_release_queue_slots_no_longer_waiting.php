<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand back every slot held by a matatu that is no longer in the line.
 *
 * `position` was assigned on joining and never given up, so a bus that departed
 * at 06:30 still held slot 1 at midnight. Because
 * `queues_terminus_route_day_position_unique` covers
 * (terminus_id, route_id, created_at::date, position), that made the slot
 * unusable for the rest of the day — the vehicle behind could not be renumbered
 * into it even deliberately.
 *
 * From here, position means ONE thing: your current place in the waiting line,
 * or NULL if you are not in it. Departed, cancelled and completed rows are not
 * in it.
 *
 * WHAT THIS COSTS, said plainly: a finished trip no longer records which
 * position it departed from. That is not recoverable alongside slot reuse —
 * the unique index forbids one row keeping a number another row must take. The
 * history is not lost though: `queue_number` is left untouched here, so a
 * completed trip still carries the "QN-2" it ran under.
 */
return new class extends Migration
{
    public function up(): void
    {
        $waiting = DB::table('queue_statuses')->where('status', 'Pending')->pluck('id');

        $released = DB::table('queues')
            ->whereNotIn('queue_status_id', $waiting)
            ->whereNotNull('position')
            ->update(['position' => null]);

        // Close the gaps that leaves, line by line, so today's screens read
        // 1..N rather than 2, 5, 9. Only lines that still have someone waiting
        // need touching, and there are very few of those at any moment.
        $lines = DB::table('queues')
            ->whereIn('queue_status_id', $waiting)
            ->whereNotNull('terminus_id')
            ->whereNotNull('route_id')
            ->selectRaw('terminus_id, route_id, created_at::date AS day')
            ->distinct()
            ->get();

        foreach ($lines as $line) {
            $slot = 0;

            $rows = DB::table('queues')
                ->whereIn('queue_status_id', $waiting)
                ->where('terminus_id', $line->terminus_id)
                ->where('route_id', $line->route_id)
                ->whereRaw('created_at::date = ?', [$line->day])
                ->orderByRaw('position IS NULL, position')
                ->orderBy('id')
                ->get(['id', 'position']);

            // Ascending, one at a time: the index is enforced per row, so a bulk
            // renumber could collide with a row it has not moved yet.
            foreach ($rows as $row) {
                $slot++;

                if ((int) $row->position === $slot) {
                    continue;
                }

                DB::table('queues')->where('id', $row->id)->update([
                    'position' => $slot,
                    'queue_number' => 'QN-'.$slot,
                ]);
            }
        }

        // Reported rather than silent: this rewrites a column three screens read.
        echo "  released {$released} stale slot(s) across {$lines->count()} line(s)\n";
    }

    public function down(): void
    {
        // Deliberately irreversible. The released positions were never recorded
        // anywhere else, so there is nothing to put back — and restoring them
        // would reinstate the very slots this migration frees.
    }
};
