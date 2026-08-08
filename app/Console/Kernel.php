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
        $schedule->command('app:check-passenger-payments')->everyTwoMinutes()->withoutOverlapping()->onOneServer();
        // Poll Daraja for STK payments whose callback was lost/delayed and confirm
        // the paid ones — must run alongside the cancel-unpaid sweep above so a paid
        // booking is recovered before it gets cancelled.
        $schedule->command('payments:reconcile')->everyTwoMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('bookings:release-expired')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->command('app:get-point-passenger-name')->everyFiveMinutes()->onOneServer();
        $schedule->command(command: 'app:create-monthly-transaction-tables')->daily()->onOneServer();
        // SACCO subscription billing: raise due invoices, then flag overdue ones.
        $schedule->command('invoices:generate')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
        $schedule->command('invoices:mark-overdue')->dailyAt('01:15')->withoutOverlapping()->onOneServer();
        // Super-admin platform console: tenant-lifecycle + platform-health detectors.
        $schedule->command('sacco:detect-dormant')->weeklyOn(1, '02:00')->withoutOverlapping()->onOneServer();
        $schedule->command('platform:daily-digest')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
        $schedule->command('platform:check-queue-backlog')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('platform:health-check')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->command('platform:check-tls')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
        $schedule->command('logs:prune')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
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
