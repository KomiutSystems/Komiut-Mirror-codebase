<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected $commands = [
        Commands\CopyMpesa::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // EVERY task below carries ->onOneServer(). The app runs as MULTIPLE
        // instances behind a load balancer, and each one runs the scheduler.
        // withoutOverlapping() only prevents a task overlapping ITSELF on the
        // SAME machine -- it does nothing across machines, so without
        // onOneServer() every one of these fires once PER INSTANCE. For
        // copy:mpesa, payments:reconcile, bookings:release-expired and
        // invoices:generate that means duplicated financial work, and it fails
        // silently: two correct-looking runs, twice the effect.
        //
        // onOneServer() takes a lock in the SHARED cache, so it is only correct
        // while CACHE_DRIVER points at redis (or another shared store). On a
        // file/array driver each instance has its own lock and every task
        // double-runs again. config/cache.php therefore defaults to redis.
        // $schedule->command('inspire')->hourly();
        // UNSCHEDULED 2026-08-08. These two pulled money rows from the LEGACY
        // hosts into this database on a timer:
        //   copy:mpesa    -> https://test.komiut.co.ke/api/mpesas/copy  (every 60s)
        //   app:copy-cash -> https://komiut.co.ke/api/cashes/copy
        //
        // Three reasons they must not run here:
        //
        // 1. `test.komiut.co.ke` is not a test environment. It and
        //    komiut.co.ke are the same host (15.152.46.244, a live t2.micro in
        //    ap-northeast-3) serving live customer payment data. This system
        //    was importing from a legacy box every minute.
        //
        // 2. The cursor is wrong. CopyMpesa reads it from `mpesas`, a table five
        //    other producers also write to, so the TransID handed to the remote
        //    is usually one the remote never issued. An unknown cursor does not
        //    error there — it replays from the beginning of history.
        //
        // 3. CopyMpesa increments the summary unconditionally on reprocess
        //    (CopyMpesa.php:124, no `if ($transaction === null)` guard, unlike
        //    C2bPaymentRecorder.php:84). Combined with (2), every replay would
        //    re-add historical fares to vehicle day totals.
        //
        // Data migration from legacy is a deliberate, reconciled, one-off
        // operation — not a cron job. Re-enable only if that changes.
        // $schedule->command('copy:mpesa')->everyMinute()->withoutOverlapping()->onOneServer();
        // $schedule->command('app:copy-cash')->everyTwoMinutes()->withoutOverlapping()->onOneServer();
        // $schedule->command('app:copy-queues')->everyTwoMinutes()->withoutOverlapping();
        // $schedule->command('app:copy-point-settings')->everyMinute();
        // $schedule->command('app:copy-points')->everyTwoMinutes();
        // $schedule->command('app:copy-point-transactions')->everyTwoMinutes();
        // Legacy points earner — superseded by event-driven loyalty (EarnLoyaltyPoints
        // on BookingPaid). Left unscheduled; the old points tables remain for history.
        // $schedule->command('app:generate-user-points')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('app:generate-vehicle-summaries')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        // Bank HO settlement sweeps land ~03:00 as O2O transfers on a shortcode no
        // vehicle owns. Hourly is ample and the command is idempotent + guarded so
        // it can never double-count a bus that collects live on its own till.
        //
        // RESCHEDULED 2026-09-09, after the date bound that makes it safe.
        //
        // It was unscheduled on 2026-08-26 because it was date-UNBOUNDED: it swept
        // every settlement in `mpesas` with no transaction, whatever its TransTime,
        // and legacy:import-money was about to drop ~1.29M historical rows
        // (TransTime 2026-07-08..2026-08-08) into that table. The next hourly tick
        // would have attributed every settlement among them -- transactions
        // written and summaries mutated for months in the past, inside an hour,
        // reviewed by nobody.
        //
        // The command now defaults to a 7-day window (DEFAULT_WINDOW_DAYS), so an
        // unattended run cannot reach the backfill however large it grows. That is
        // the third of the three conditions the old comment set, and it is the one
        // that subsumes the other two: history is out of scope by construction, so
        // the schedule no longer depends on the backfill being reconciled first.
        //
        // IT SWEEPS NOTHING TODAY, AND THAT IS THE RIGHT ANSWER. Measured
        // 2026-09-09: all 195 settlements lacking a transaction are TransTime
        // 2026-07-31..2026-08-08 -- the backfill -- and none fall in the 7-day
        // window. So the first tick attributes zero. The line is here to catch
        // settlements that arrive with no transaction from now on, not to clear a
        // backlog.
        //
        // It is NOT the fix for the KES 5,515,605.74 across 335 transactions that
        // currently hold vehicle_id NULL. Those already HAVE transaction rows, and
        // this command only ever selects mpesas with none -- see the class
        // docblock. Roughly thirty O2O settlements a day are written unattributed
        // by something upstream (2026-09-08: 521 O2O, 521 transactions, 491 with a
        // vehicle). Do not widen the window here hoping to reach them; the window
        // cannot reach them and widening it only attributes the backfill.
        //
        // Reaching further back is now an explicit, reviewable act:
        //     php artisan app:attribute-coop-settlements --since=2026-07-01 --dry-run
        // which reports every row it would write and writes nothing. Run that, read
        // it, and only then drop --dry-run. Never widen the window HERE.
        $schedule->command('app:attribute-coop-settlements')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('app:check-passenger-payments')->everyTwoMinutes()->withoutOverlapping()->onOneServer();
        // Poll Daraja for STK payments whose callback was lost/delayed and confirm
        // the paid ones — must run alongside the cancel-unpaid sweep above so a paid
        // booking is recovered before it gets cancelled.
        $schedule->command('payments:reconcile')->everyTwoMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('bookings:release-expired')->everyMinute()->withoutOverlapping()->onOneServer();
        // A not-boarded refund that failed mid-request (ledger error, a deploy
        // rolling the container) is cancelled-with-no-refund forever unless
        // something retries it. This does, on the persisted cancellation reason.
        $schedule->command('bookings:repair-refunds')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('app:get-point-passenger-name')->everyFiveMinutes()->onOneServer();
        $schedule->command(command: 'app:create-monthly-transaction-tables')->daily()->onOneServer();
        // SACCO subscription billing: raise due invoices, then flag overdue ones.
        $schedule->command('invoices:generate')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
        $schedule->command('invoices:mark-overdue')->dailyAt('01:15')->withoutOverlapping()->onOneServer();

        // Collections statement to each financier bank, on the 1st for the
        // month that just closed. 05:00 so it lands before a banking day and
        // after the overnight billing above has settled.
        //
        // It is idempotent per (bank, period) and fails closed on a missing
        // address, so a retry or a second instance cannot put two statements
        // in a bank's inbox.
        $schedule->command('bank:send-statement')
            ->monthlyOn(1, '05:00')->withoutOverlapping()->onOneServer();

        // Tills that have gone quiet, and payments for tills we do not know.
        //
        // KDY 599G ran for a MONTH before anyone noticed: its till was live at
        // Safaricom and taking money, but the C2B callback for its shortcode was
        // never registered, so nothing reached us. Its record looked perfect --
        // only the ABSENCE of rows gave it away, and nothing was watching for
        // absences. Weekly, so it surfaces on day eight rather than day thirty.
        $schedule->command('tills:check-idle')
            ->weeklyOn(1, '06:30')->withoutOverlapping()->onOneServer();

        // The same idea as the check above, one level up: is anything LEGACY
        // received failing to reach this system at all?
        //
        // Nothing else in the migration measures that, and the reason it needs
        // measuring is that no component on either side reports a failure when a
        // payment goes missing. Safaricom is acked before the work is done
        // (C2bConfirmationController says so in as many words), C2bPaymentRecorder
        // catches Throwable, this scheduler exits 0. A lost payment therefore
        // produces an absence and nothing else — and an absence is only visible by
        // comparing the two systems. Measured read-only on 2026-08-26, that
        // absence was 76 payments / KES 7,050 in a single hour, none of which had
        // raised anything anywhere.
        //
        // Every fifteen minutes over a SIXTY-minute window, so each minute is
        // examined about four times. The overlap is deliberate: it is read-only
        // and idempotent, so a minute that looks short only because legacy was
        // briefly behind gets re-examined by the next three runs and heals itself,
        // and the notifier's dedupe window folds the repeats onto one open row
        // instead of paging four times an hour.
        //
        // onOneServer() for the reason at the top of this method, though what it
        // buys here is different in kind: this command writes nothing, so a
        // per-instance run would not duplicate financial work — it would multiply
        // the alerts, and the query load on a live legacy box, by the instance
        // count.
        //
        // INERT until LEGACY_DB_* is set (see config/database.php): with no route
        // to legacy it fails closed and files a once-a-day review notice rather
        // than reporting a reconciled zero it never actually checked.
        $schedule->command('payments:reconcile-legacy')
            ->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

        // The completeness check that does NOT need legacy — it compares us
        // against Safaricom's own running till balance, so it keeps working
        // after Mumbai is switched off. Hourly on a trailing window catches an
        // outage the same morning; the daily run re-checks the closed day, when
        // nothing is in flight and the figure is final.
        $schedule->command('payments:audit-till-ledger --hours=3')
            ->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('payments:audit-till-ledger')
            ->dailyAt('05:30')->withoutOverlapping()->onOneServer();
        // Super-admin platform console: tenant-lifecycle + platform-health detectors.
        $schedule->command('sacco:detect-dormant')->weeklyOn(1, '02:00')->withoutOverlapping()->onOneServer();
        $schedule->command('platform:daily-digest')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
        // Trip queues nobody ended. A queue leaves Active only when a driver
        // taps end, so a dead phone or a force-closed app strands it open --
        // and while it is open the vehicle cannot join a queue on any other
        // route (409). KCE069C sat like that for 26 days before anyone noticed.
        // Hourly, because the cost of a stranded queue is a driver who cannot
        // work.
        $schedule->command('queues:close-stale')
            ->hourly()->withoutOverlapping()->onOneServer();

        $schedule->command('platform:check-queue-backlog')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('platform:health-check')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->command('platform:check-tls')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
        $schedule->command('logs:prune')->dailyAt('04:00')->withoutOverlapping()->onOneServer();

        // Expired tokens stay in personal_access_tokens until something deletes
        // them: Sanctum stops ACCEPTING them at expiry but never removes the
        // row. Nothing pruned them, so the table only ever grew — and with
        // drivers now signing in daily, that is one row per driver per day
        // accumulating forever, each holding a token hash.
        $schedule->command('sanctum:prune-expired --hours=24')
            ->dailyAt('04:15')->withoutOverlapping()->onOneServer();
        // $schedule->command('app:copy-qrcode-payments')->everyMinute()->withoutOverlapping();
        /*$schedule->command('queue:work --stop-when-empty')
        ->everyMinute()->withoutOverlapping();*/
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
