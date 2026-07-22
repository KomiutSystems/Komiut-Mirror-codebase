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
        // $schedule->command('inspire')->hourly();
        $schedule->command('brand:each copy:mpesa')->everyMinute()->withoutOverlapping();
        $schedule->command('brand:each app:copy-cash')->everyTwoMinutes()->withoutOverlapping();
        //$schedule->command('app:copy-queues')->everyTwoMinutes()->withoutOverlapping();
        //$schedule->command('app:copy-point-settings')->everyMinute();
        //$schedule->command('app:copy-points')->everyTwoMinutes();
        //$schedule->command('app:copy-point-transactions')->everyTwoMinutes();
        $schedule->command('brand:each app:generate-user-points')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('brand:each app:generate-vehicle-summaries')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('brand:each app:check-passenger-payments')->everyTwoMinutes()->withoutOverlapping();
        $schedule->command('brand:each app:get-point-passenger-name')->everyFiveMinutes();
        $schedule->command(command: 'brand:each app:create-monthly-transaction-tables')->daily();
        //$schedule->command('app:copy-qrcode-payments')->everyMinute()->withoutOverlapping();
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
