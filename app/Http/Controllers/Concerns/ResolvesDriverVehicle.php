<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Booking;
use App\Models\Queue;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Illuminate\Http\JsonResponse;

/**
 * Which vehicle is this driver on RIGHT NOW.
 *
 * One home for the rule, because every driver endpoint depends on it and a
 * second copy is exactly how the two would drift apart. Crews rotate between
 * matatus and money is paid to the vehicle's till, not the person, so the open
 * assignment — not the driver's history — is what identifies today's bus.
 *
 * This is also the authorization boundary: a driver may only read or change
 * things belonging to the vehicle resolved here, which is why nothing takes a
 * vehicle id from the request.
 */
trait ResolvesDriverVehicle
{
    /** The vehicle on the caller's open assignment, or null if they are off shift. */
    protected function vehicle(): ?Vehicle
    {
        $assignment = VehicleUser::with('vehicle.sacco', 'vehicle.seat')
            ->where('user_id', auth()->id())
            ->where('status', true)
            ->whereNull('end_date')
            ->latest('id')
            ->first();

        return $assignment?->vehicle;
    }

    protected function noAssignment(): JsonResponse
    {
        return response()->json(['error' => 'You have no active vehicle assignment.'], 403);
    }

    /** The queue this vehicle is loading or running, if any. */
    protected function currentQueue(int $vehicleId): ?Queue
    {
        return Queue::with(['route.from', 'route.to', 'terminus.place', 'queue_status'])
            ->where('vehicle_id', $vehicleId)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Active', 'Pending']))
            ->latest('id')
            ->first();
    }

    /**
     * A booking on THIS vehicle's current queue, or null.
     *
     * The dashboard's equivalents take a booking id straight from the request
     * with no ownership check at all, so a driver could board or cancel a
     * passenger on somebody else's bus.
     */
    protected function ownBooking(Vehicle $vehicle, int $bookingId): ?Booking
    {
        $queue = $this->currentQueue((int) $vehicle->id);
        if ($queue === null) {
            return null;
        }

        return Booking::where('id', $bookingId)->where('queue_id', $queue->id)->first();
    }
}
