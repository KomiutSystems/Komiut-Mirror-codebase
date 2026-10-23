<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\VehicleAlert;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use App\Services\Owner\AlertRaiser;
use App\Support\BusinessDay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A bus on shift that has stopped reporting its position.
 *
 * Only between 05:00 and 22:00 Nairobi: a bus parked overnight is not a fault,
 * and waking an owner at 02:00 to say so is how an alert gets muted forever.
 * Resolution happens HERE, on the five-minute pass, rather than in the location
 * ingest — that endpoint takes hundreds of pings a minute from driver phones
 * and is the last place to add a write.
 */
class DetectOfflineVehicles extends Command
{
    protected $signature = 'vehicles:detect-offline';

    protected $description = 'Raise and resolve offline alerts for buses on shift';

    private const DAY_START = 5;

    private const DAY_END = 22;

    public function handle(AlertRaiser $alerts): int
    {
        $now = Carbon::now(BusinessDay::TIMEZONE);

        if ($now->hour < self::DAY_START || $now->hour >= self::DAY_END) {
            $this->info('outside 05:00-22:00, nothing to do');

            return self::SUCCESS;
        }

        [$shiftFrom] = BusinessDay::windowFor($now);

        // On shift: someone signed in for this bus inside the current business
        // day and has not signed off.
        $onShift = VehicleUser::withoutGlobalScopes()
            ->whereNull('end_date')
            ->where('status', true)
            ->where('start_date', '>=', $shiftFrom)
            ->pluck('vehicle_id')
            ->unique();

        $raised = 0;
        $resolved = 0;

        foreach ($onShift as $vehicleId) {
            $location = VehicleLocation::withoutGlobalScopes()->where('vehicle_id', $vehicleId)->first();
            $lastSeen = $location?->recorded_at;

            // Never reported at all today is not "went offline" — there is no
            // episode to close and nothing to say about a gap that never began.
            if ($lastSeen === null) {
                continue;
            }

            $minutesQuiet = (int) $lastSeen->diffInMinutes($now);
            $vehicle = Vehicle::withoutGlobalScopes()->find($vehicleId);

            if ($vehicle === null) {
                continue;
            }

            $raised += $alerts->raise(
                $vehicle,
                VehicleAlert::OFFLINE,
                ['last_seen_at' => $lastSeen->toIso8601String(), 'minutes_offline' => $minutesQuiet],
                ['time' => $lastSeen->copy()->timezone(BusinessDay::TIMEZONE)->format('H:i')],
                'notifications.vehicle_alert.offline.title',
                'notifications.vehicle_alert.offline.body',
                // The raiser compares this against each owner's own threshold
                // and closes the episode for anyone it no longer meets.
                $minutesQuiet,
            );
        }

        // The noon alert's other half. Resolved here rather than where a payment
        // is marked paid: C2bPaymentRecorder turns any throw into ResultCode 1
        // on a live payment, so nothing extra belongs on that path.
        foreach ($onShift as $vehicleId) {
            $open = VehicleAlert::where('vehicle_id', $vehicleId)
                ->where('type', VehicleAlert::NO_COLLECTIONS)
                ->whereNull('resolved_at')
                ->exists();

            if ($open && Transaction::withoutGlobalScopes()
                ->where('vehicle_id', $vehicleId)
                ->where('trans_date', '>=', $shiftFrom)
                ->exists()) {
                $resolved += $alerts->resolve((int) $vehicleId, VehicleAlert::NO_COLLECTIONS);
            }
        }

        $this->info(sprintf('offline: %d raised, %d resolved', $raised, $resolved));

        return self::SUCCESS;
    }

}
