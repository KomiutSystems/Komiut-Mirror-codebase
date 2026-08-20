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
    public function the_trips_count_excludes_cancelled_queues(): void
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

        // Two real trips today...
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $completed, $world['owner'], 'QN-COMPLETED');
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner'], 'QN-ACTIVE');
        // ...and one the driver bailed on — must not count.
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $cancelled, $world['owner'], 'QN-CANCELLED');

        Sanctum::actingAs($driver);

        $this->getJson('/api/auth/driver/earnings')
            ->assertOk()
            ->assertJsonPath('takings.trips', 2);
    }
}
