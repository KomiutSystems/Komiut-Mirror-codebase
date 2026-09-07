<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The driver home/earnings "trips" figure counts the trips a bus actually ran,
 * not the queues it abandoned. A driver who joins a stage then exits leaves a
 * Cancelled queue behind; those must NOT inflate the day's trip count. Only
 * Completed (and still-running Active) queues are real trips.
 */
final class DriverPortalTripsCountTest extends QueueTestCase
{
    #[Test]
    public function only_a_trip_the_driver_ended_is_counted(): void
    {
        $world = $this->makeWorld();

        // A driver assigned to the world's vehicle. The portal is gated by
        // identity (the open assignment), not by a permission.
        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        $completed = $this->makeQueueStatus('Completed', 'Completed');
        $active = $this->makeQueueStatus('Active', 'Active');
        $cancelled = $this->makeQueueStatus('Cancelled', 'Cancelled');

        // One trip the driver ended...
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $completed, $world['owner'], 'QN-COMPLETED');
        // ...one still on the road. Departing is not arriving, and the figure
        // beside this number reads "trips completed". Counted until
        // 2026-09-07; it does not count now.
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'], 'QN-ACTIVE');
        // ...and one the driver bailed on.
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $cancelled, $world['owner'], 'QN-CANCELLED');

        Sanctum::actingAs($driver);

        $this->getJson('/api/auth/driver/earnings')
            ->assertOk()
            ->assertJsonPath('takings.trips', 1);
    }

    #[Test]
    public function ending_the_trip_is_what_makes_it_count(): void
    {
        // The whole point, stated as the transition rather than as a snapshot:
        // the same queue counts for nothing while it is running and for one the
        // moment the driver taps End trip.
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        $active = $this->makeQueueStatus('Active', 'Active');
        $completed = $this->makeQueueStatus('Completed', 'Completed');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'], 'QN-RUNNING');

        Sanctum::actingAs($driver);

        $this->getJson('/api/auth/driver/earnings')->assertOk()->assertJsonPath('takings.trips', 0);

        $queue->forceFill(['queue_status_id' => $completed->id])->save();

        $this->getJson('/api/auth/driver/earnings')->assertOk()->assertJsonPath('takings.trips', 1);
    }
}
