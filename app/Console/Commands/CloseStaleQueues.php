<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Queue;
use App\Models\QueueStatus;
use App\Services\Platform\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Close trip queues nobody ever ended.
 *
 * A queue leaves Pending only when a driver taps start, and leaves Active only
 * when a driver taps end. There was no third way out. So a phone that dies
 * mid-trip, an app force-closed at the stage, or a driver who simply knocks off
 * without tapping anything leaves the queue open FOREVER.
 *
 * THAT IS NOT COSMETIC — IT LOCKS THE DRIVER OUT. Joining a queue refuses with
 * 409 "This vehicle is already queued on another route" while any Pending or
 * Active queue exists for the vehicle, startTrip hands back the stale one
 * instead of starting a new trip, and currentQueue keeps reporting a journey
 * that ended days ago. Found on 2026-09-06: KCE069C had been stuck Active since
 * 11 August, twenty-six days, unable to queue on any other route the whole time.
 *
 * Nothing was watching for it either. `platform:check-queue-backlog` sounds like
 * it would and does not — that one measures the Laravel `jobs` table, a
 * different thing wearing the same word.
 *
 * CANCELLED, NOT COMPLETED, and the distinction matters. We do not know these
 * trips ran. Marking an abandoned queue Completed would mint a trip that may
 * never have happened, and trip counts feed the driver earnings screen and the
 * SACCO's trip reports. Cancelled says only what is certain: it is not running
 * now, and nobody closed it properly.
 *
 * The window is deliberately generous. The longest route on the platform is a
 * few hours, so twelve leaves no honest trip at risk of being swept mid-journey
 * while still clearing the same operating day.
 */
class CloseStaleQueues extends Command
{
    protected $signature = 'queues:close-stale
        {--hours=12 : Close queues still open this long after they started}
        {--dry-run : List what would be closed and change nothing}';

    protected $description = 'Close trip queues left Pending or Active long past any real journey';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $cancelled = QueueStatus::where('status', 'Cancelled')->first();
        if ($cancelled === null) {
            $this->error('No Cancelled queue status configured — refusing to guess at one.');

            return self::FAILURE;
        }

        $stale = Queue::withoutGlobalScopes()
            ->whereIn('queue_status_id', QueueStatus::whereIn('status', ['Pending', 'Active'])->pluck('id'))
            ->where('start_time', '<', $cutoff)
            ->orderBy('start_time')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No queue has been open longer than {$hours}h.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($stale as $queue) {
            $was = QueueStatus::find($queue->queue_status_id)?->status ?? 'unknown';
            $openHours = (int) $cutoff->addHours($hours)->diffInHours($queue->start_time, true);

            // Reported rather than used as a guard. Bookings on a queue this old
            // have already been released by bookings:release-expired, and a queue
            // left open BECAUSE it holds a booking is the very lock-out this
            // command exists to clear — so the count is recorded for traceability
            // and does not stop the close.
            $bookings = (int) DB::table('bookings')->where('queue_id', $queue->id)->count();

            $rows[] = [$queue->id, $queue->vehicle_id, $was, $openHours.'h', $bookings];

            if ($dryRun) {
                continue;
            }

            $queue->queue_status_id = $cancelled->id;
            $queue->end_time = $queue->end_time ?? now();
            $queue->save();

            AuditLogger::record(
                action: 'queue.stale.closed',
                data: [
                    'queue_id' => (int) $queue->id,
                    'vehicle_id' => (int) $queue->vehicle_id,
                    'was' => $was,
                    'now' => 'Cancelled',
                    'open_hours' => $openHours,
                    'bookings' => $bookings,
                    'threshold_hours' => $hours,
                ],
                actor: ['type' => 'system', 'id' => 'queues:close-stale', 'label' => 'stale queue sweep'],
                brand: null,
            );
        }

        $this->table(['queue', 'vehicle', 'was', 'open', 'bookings'], $rows);
        $this->info(($dryRun ? 'Would close ' : 'Closed ').count($rows)." queue(s) open longer than {$hours}h.");

        return self::SUCCESS;
    }
}
