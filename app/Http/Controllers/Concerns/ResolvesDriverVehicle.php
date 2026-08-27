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
    /**
     * The vehicle the caller is working with, or null if none is theirs.
     *
     * With no argument this is the most recent open assignment — right for a
     * driver or a conductor, who are on exactly one bus today.
     *
     * IT IS NOT RIGHT FOR AN OWNER. Investors are attached to their buses
     * through this same table: at NICCO ten of them hold open assignments, one
     * across 40 vehicles and another across 20. `latest('id')` showed each of
     * them a single arbitrary bus and hid the rest of their fleet, because the
     * rule was written for someone on shift and an owner is not on shift — they
     * have N buses at once.
     *
     * So a caller may NAME a vehicle, and the name is checked against their own
     * assignments rather than trusted. An id that is not theirs matches no row
     * and comes back null, which every caller already renders as "you have no
     * active vehicle assignment". The authorization boundary the class docblock
     * describes is intact: the request can narrow this, never widen it.
     */
    protected function vehicle(?int $vehicleId = null): ?Vehicle
    {
        $assignment = $this->assignments()
            ->with('vehicle.sacco', 'vehicle.seat')
            ->when($vehicleId !== null, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->latest('id')
            ->first();

        return $assignment?->vehicle;
    }

    /**
     * Every vehicle the caller is currently attached to.
     *
     * One row for a driver, a whole fleet for an investor. Ordered by plate so
     * the list a person sees does not reshuffle between requests the way
     * assignment id would.
     *
     * @return \Illuminate\Support\Collection<int, Vehicle>
     */
    protected function myVehicles(): \Illuminate\Support\Collection
    {
        return $this->assignments()
            ->with('vehicle.sacco')
            ->get()
            ->map(fn (VehicleUser $vu) => $vu->vehicle)
            ->filter()
            ->unique('id')
            ->sortBy('plate')
            ->values();
    }

    /** The caller's open assignments. The one place that rule is written. */
    private function assignments()
    {
        return VehicleUser::where('user_id', auth()->id())
            ->where('status', true)
            ->whereNull('end_date');
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
