<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform.queue.backlog (alert / high) — depth and oldest-job age of the database
 * `jobs` table against the queue_backlog threshold. Platform-wide (brand-null).
 * Only meaningful for the database queue driver; a missing table is a clean skip.
 */
class CheckQueueBacklog extends Command
{
    protected $signature = 'platform:check-queue-backlog';

    protected $description = 'Alert when the pending-jobs backlog exceeds the configured depth / age';

    public function handle(PlatformNotifier $notifier): int
    {
        if (! Schema::hasTable('jobs')) {
            $this->warn('jobs table missing (non-database queue driver) — skipping.');

            return self::SUCCESS;
        }

        $threshold = (array) Thresholds::get(null, 'queue_backlog');
        $maxDepth = (int) ($threshold['depth'] ?? 1000);
        $maxAge = (int) ($threshold['oldest_job_age_s'] ?? 300);

        $depth = (int) DB::table('jobs')->count();
        $oldestAvailableAt = DB::table('jobs')->min('available_at'); // unix timestamp
        $oldestAge = $oldestAvailableAt !== null ? max(0, time() - (int) $oldestAvailableAt) : 0;

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
