<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApplicationLog;
use App\Models\RequestLog;
use Illuminate\Console\Command;

/**
 * Prunes the DB-backed logs (request_logs, application_logs) so they don't grow
 * unbounded. Deletes rows older than --days (default 30).
 *
 * NOT scheduled here — app/Console/Kernel.php is owned by another agent. It
 * should be added to the schedule (e.g. `->daily()`).
 */
class PruneLogs extends Command
{
    protected $signature = 'logs:prune {--days=30 : Delete log rows older than this many days}';

    protected $description = 'Delete request_logs and application_logs older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $requests = RequestLog::where('created_at', '<', $cutoff)->delete();
        $application = ApplicationLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$requests} request log(s) and {$application} application log(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
