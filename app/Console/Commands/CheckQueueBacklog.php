<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * platform.queue.backlog (alert / high) — depth and oldest-job age of the queue
 * the worker consumes, against the queue_backlog threshold. Platform-wide
 * (brand-null).
 *
 * Asks the configured queue connection, whatever its driver. It used to count
 * the database `jobs` table, but production queues on Redis: that table exists
 * (the migration does) and is always empty, so this reported "healthy" however
 * far behind the worker fell. Pending jobs only -- not the ones a worker is
 * running or that wait out a retry delay, which are not a backlog.
 */
class CheckQueueBacklog extends Command
{
    protected $signature = 'platform:check-queue-backlog';

    protected $description = 'Alert when the pending-jobs backlog exceeds the configured depth / age';

    public function handle(PlatformNotifier $notifier): int
    {
        $threshold = (array) Thresholds::get(null, 'queue_backlog');
        $maxDepth = (int) ($threshold['depth'] ?? 1000);
        $maxAge = (int) ($threshold['oldest_job_age_s'] ?? 300);

        $queue = Queue::connection();
        $depth = (int) $queue->pendingSize();
        $oldestCreatedAt = $queue->creationTimeOfOldestPendingJob(); // unix timestamp
        $oldestAge = $oldestCreatedAt !== null ? max(0, time() - (int) $oldestCreatedAt) : 0;

        if ($depth < $maxDepth && $oldestAge < $maxAge) {
            $this->info("Queue healthy — depth {$depth}, oldest {$oldestAge}s.");

            return self::SUCCESS;
        }

        $notifier->dispatch(new PlatformEvent(
            event: 'platform.queue.backlog',
            severity: 'high',
            class: 'alert',
            title: 'Queue backlog',
            summary: mb_substr("Jobs pending: {$depth} (max {$maxDepth}); oldest {$oldestAge}s (max {$maxAge}s).", 0, 140),
            brand: null,
            subject: ['type' => 'queue', 'id' => 'default'],
            data: [
                'depth' => $depth,
                'oldestJobAgeSeconds' => $oldestAge,
                'thresholdDepth' => $maxDepth,
                'thresholdOldestJobAgeSeconds' => $maxAge,
            ],
            dedupeKey: 'platform.queue.backlog',
            windowMinutes: 30,
        ));

        $this->warn("Queue backlog flagged — depth {$depth}, oldest {$oldestAge}s.");

        return self::SUCCESS;
    }
}
