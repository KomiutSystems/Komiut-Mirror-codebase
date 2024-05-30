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
        $schedule->command('copy:mpesa')->everyMinute()->withoutOverlapping();
        $schedule->command('app:generate-user-points')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('app:generate-vehicle-summaries')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('app:check-passenger-payments')->everyTwoMinutes()->withoutOverlapping();
        $schedule->command('app:get-point-passenger-name')->everyFiveMinutes();
        $schedule->command('queue:work --stop-when-empty')
        ->everyMinute()->withoutOverlapping();
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
