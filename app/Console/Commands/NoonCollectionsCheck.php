<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\VehicleAlert;
use App\Models\VehicleUser;
use App\Services\Owner\AlertRaiser;
use App\Support\BusinessDay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * At noon: a bus that has been working since before 11:00 and has taken nothing.
 *
 * The hour of grace matters — a bus that signed in at 10:50 may simply not have
 * reached its first stage, and an alert about it is noise the owner learns to
 * ignore.
 */
class NoonCollectionsCheck extends Command
{
    protected $signature = 'vehicles:noon-check';

    protected $description = 'Alert owners about buses that have taken no payment by noon';

    public function handle(AlertRaiser $alerts): int
    {
        $now = Carbon::now(BusinessDay::TIMEZONE);
        [$dayFrom] = BusinessDay::windowFor($now);
        $elevenToday = $now->copy()->setTime(11, 0);

        $candidates = VehicleUser::withoutGlobalScopes()
            ->whereNull('end_date')
            ->where('status', true)
            ->where('start_date', '>=', $dayFrom)
            ->where('start_date', '<', $elevenToday)
            ->get(['vehicle_id', 'start_date'])
            ->unique('vehicle_id');

        $raised = 0;

        foreach ($candidates as $shift) {
            $tookMoney = Transaction::withoutGlobalScopes()
                ->where('vehicle_id', $shift->vehicle_id)
                ->where('trans_date', '>=', $dayFrom)
                ->exists();

            if ($tookMoney) {
                continue;
            }

            $vehicle = Vehicle::withoutGlobalScopes()->find($shift->vehicle_id);

            if ($vehicle === null) {
                continue;
            }

            $since = Carbon::parse($shift->start_date);

            $raised += $alerts->raise(
                $vehicle,
                VehicleAlert::NO_COLLECTIONS,
                ['crewed_since' => $since->toIso8601String()],
                ['since' => $since->copy()->timezone(BusinessDay::TIMEZONE)->format('H:i')],
                'notifications.vehicle_alert.noon.title',
                'notifications.vehicle_alert.noon.body',
            );
        }

        $this->info(sprintf('noon check: %d raised', $raised));

        return self::SUCCESS;
    }
}
