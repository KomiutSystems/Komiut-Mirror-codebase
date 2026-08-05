<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * platform.health.down (alert / critical) — a DB-connectivity self-check that
 * emits only after N consecutive failures, so a single transient blip stays quiet.
 *
 * The failure counter is pinned to the `file` cache store, not the default: if the
 * default store were `database` it would die with the very outage we track. The
 * notification write itself needs the DB, so the useful signal is really the
 * recovery edge (the counter carries the outage until the DB answers again); the
 * emit is wrapped so a still-down DB degrades quietly.
 */
class CheckPlatformHealth extends Command
{
    protected $signature = 'platform:health-check';

    protected $description = 'Emit platform.health.down after consecutive DB connectivity failures';

    private const KEY = 'platform:health:db:consecutive_failures';

    private const THRESHOLD = 2;

    public function handle(PlatformNotifier $notifier): int
    {
        $store = Cache::store('file');

        try {
            DB::connection()->select('select 1');
            $store->forget(self::KEY);
            $this->info('DB reachable.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $failures = (int) $store->get(self::KEY, 0) + 1;
            $store->put(self::KEY, $failures, now()->addHours(6));

            $this->warn("DB self-check failed ({$failures} consecutive).");

            if ($failures < self::THRESHOLD) {
                return self::FAILURE;
            }

            try {
                $notifier->dispatch(new PlatformEvent(
                    event: 'platform.health.down',
                    severity: 'critical',
                    class: 'alert',
                    title: 'Platform DB unreachable',
                    summary: mb_substr("Database self-check failed {$failures} consecutive times.", 0, 140),
                    brand: null,
                    subject: ['type' => 'platform', 'id' => 'database'],
                    data: [
                        'consecutiveFailures' => $failures,
                        'check' => 'database',
                    ],
                    dedupeKey: 'platform.health.down:database',
                    windowMinutes: 30,
                ));
            } catch (Throwable $emitError) {
                // DB still down — nothing to persist to. The counter survives in the
                // file store and this emits on the recovery check.
                $this->error('Could not persist health alert (DB still down).');
            }

            return self::FAILURE;
        }
    }
}
