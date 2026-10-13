<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApplicationLog;
use App\Models\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prunes the DB-backed logs so they don't grow unbounded: request_logs and
 * application_logs past --days, raw M-Pesa callback bodies (mpesa_logs) past
 * --mpesa-days. Scheduled daily in app/Console/Kernel.php.
 *
 * Everything is deleted in chunks of CHUNK rows, each its own short statement:
 * these tables take inserts all day (a confirmation every two seconds), and
 * one DELETE over weeks of rows is one long transaction and a burst of WAL
 * that every standby has to replay.
 */
class PruneLogs extends Command
{
    private const CHUNK = 5000;

    protected $signature = 'logs:prune
        {--days=30 : Delete request/application log rows older than this many days}
        {--mpesa-days=90 : Delete raw M-Pesa callback bodies (mpesa_logs) older than this many days}';

    protected $description = 'Delete request_logs, application_logs and old mpesa_logs past their retention windows';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // Both have an index on created_at.
        $requests = $this->inChunks(fn () => RequestLog::where('created_at', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id'), 'request_logs');
        $application = $this->inChunks(fn () => ApplicationLog::where('created_at', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id'), 'application_logs');

        // mpesa_logs: the raw body of every confirmation, kept so a payment we
        // failed to record can be rebuilt. That is a days-to-weeks question;
        // the payments themselves (mpesas, transactions) are never pruned.
        //
        // No index on created_at, and ~43k rows a day: "WHERE created_at <
        // cutoff" would read the whole table every night just to find nothing.
        // Ids grow with time, so walk from the oldest id and stop at the first
        // batch that reaches into the window.
        $mpesaDays = max(30, (int) $this->option('mpesa-days'));
        $mpesaCutoff = now()->subDays($mpesaDays)->toDateTimeString();
        $raw = 0;
        $after = 0;
        do {
            $batch = DB::table('mpesa_logs')->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->get(['id', 'created_at']);
            $old = $batch->filter(fn ($row) => $row->created_at !== null && (string) $row->created_at < $mpesaCutoff)->pluck('id');
            if ($old->isNotEmpty()) {
                $raw += DB::table('mpesa_logs')->whereIn('id', $old)->delete();
            }
            $after = (int) $batch->last()?->id;
            $reachedWindow = $batch->contains(fn ($row) => $row->created_at !== null && (string) $row->created_at >= $mpesaCutoff);
        } while ($batch->count() === self::CHUNK && ! $reachedWindow);

        $this->info("Pruned {$requests} request log(s) and {$application} application log(s) older than {$days} day(s), and {$raw} raw M-Pesa callback(s) older than {$mpesaDays} day(s).");

        return self::SUCCESS;
    }

    /** @param  \Closure(): \Illuminate\Support\Collection<int, int>  $nextIds */
    private function inChunks(\Closure $nextIds, string $table): int
    {
        $deleted = 0;
        do {
            $ids = $nextIds();
            $deleted += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
