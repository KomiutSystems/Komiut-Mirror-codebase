<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Location\VehicleLocationService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A bus is available for booking when it is LIVE, not when it is queued.
 *
 * Decided 2026-09-12. At the main terminus passengers walk on and pay the
 * conductor; the booking flow is for the bus on the road, and the driver's
 * phone broadcasting is the offer. KDN 458N was live on the Nairobi-Thika
 * road that morning, on the dashboard's map, and missing from the passenger
 * app: its last queue had completed, and the passenger list was a list of
 * queues.
 *
 * Two halves. Going live on a route with no open queue CREATES the trip
 * (LiveRun) -- so there is something to book onto, track, and for the crew
 * to end. And the passenger list is filtered to buses that pinged inside the
 * live window, whatever their queue status: a queued bus whose driver is not
 * live is not on offer; a bus that has gone quiet drops off.
 */
final class LiveIsBookableTest extends QueueTestCase
{
    private const PING = '/api/v1/auth/book_a_ride/location';

    private function driver(array $world): User
    {
        $d = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $d->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return $d;
    }

    private function list(array $world): array
    {
        return $this->getJson('/api/v1/auth/book_a_ride/queues?from_id='.$world['from']->id.'&to_id='.$world['to']->id)
            ->assertOk()->json('queues');
    }

    #[Test]
    public function a_bus_that_goes_live_on_a_route_is_on_a_trip_and_the_passenger_can_see_and_book_it(): void
    {
        $this->makeQueueStatus('Active', 'Active');
        $world = $this->makeWorld();
        $this->assertSame(0, Queue::count(), 'no queue anywhere: the bus just turned around at the far end');

        // The driver's phone pings, on the route, with no queue.
        Sanctum::actingAs($this->driver($world));
        $ping = $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93, 'route_id' => $world['route']->id])
            ->assertStatus(202);

        // Going live created the trip: Active, on the route, no stage slot.
        $run = Queue::withoutGlobalScopes()->findOrFail($ping->json('queue_id'));
        $this->assertSame('Active', $run->queue_status->status);
        $this->assertSame((int) $world['route']->id, (int) $run->route_id);
        $this->assertNull($run->position, 'not in any terminus line');
        $this->assertSame('LIVE', $run->queue_number);
        $this->assertNotNull($run->departed_at);

        // A second ping reuses it; nothing is minted twice.
        $this->postJson(self::PING, ['latitude' => -1.19, 'longitude' => 36.94, 'route_id' => $world['route']->id])
            ->assertStatus(202)->assertJsonPath('queue_id', $run->id);
        $this->assertSame(1, Queue::withoutGlobalScopes()->count());

        // The passenger sees it on the route, with where it is right now.
        $tom = $this->makeUser();
        Sanctum::actingAs($tom);
        $rows = $this->list($world);
        $this->assertCount(1, $rows);
        $this->assertSame($run->id, $rows[0]['id']);
        $this->assertEqualsWithDelta(-1.19, $rows[0]['live']['latitude'], 0.0001);
        $this->assertLessThanOrEqual(5, $rows[0]['live']['age_seconds']);

        // And books onto it -- the same trip the tracker will follow.
        $seats = $world['arrangements'];
        $booked = $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $run->id, 'seats' => (string) $seats[0]->id, 'name' => 'Tom', 'phone' => '0722123456',
        ])->assertOk();
        $this->assertSame($run->id, (int) Booking::withoutGlobalScopes()->find($booked->json('booking_id'))->queue_id);
        $this->getJson('/api/v1/auth/bookings/passengers/track/'.$booked->json('booking_id'))
            ->assertOk()->assertJsonPath('trip.channel', 'trip.'.$run->id)->assertJsonPath('bus.live', true);
    }

    #[Test]
    public function a_queued_bus_whose_driver_is_not_live_is_not_on_offer(): void
    {
        $world = $this->makeWorld();
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending', 'Pending'), $world['owner']);

        Sanctum::actingAs($this->makeUser());
        $this->assertSame([], $this->list($world), 'queued, but nobody is broadcasting: at the terminus they pay the conductor');
    }

    #[Test]
    public function a_bus_that_went_quiet_drops_off_the_list(): void
    {
        $world = $this->makeWorld();
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active', 'Active'), $world['owner']);

        Sanctum::actingAs($this->makeUser());

        $this->goLive($world['vehicle'], $queue, ageSeconds: 10);
        $this->assertCount(1, $this->list($world), 'pinged ten seconds ago: live');

        $this->goLive($world['vehicle'], $queue, ageSeconds: VehicleLocationService::FRESH_SECONDS + 30);
        $this->assertSame([], $this->list($world), 'no ping inside the live window: not on offer');
    }

    #[Test]
    public function stopping_the_broadcast_hides_the_bus_but_does_not_end_the_trip(): void
    {
        // Going off the map is not ending the trip -- that stays the crew's
        // explicit action, with its passengers marked. The run, and any
        // booking on it, wait for driver/trip/end.
        $this->makeQueueStatus('Active', 'Active');
        $world = $this->makeWorld();
        $driver = $this->driver($world);

        Sanctum::actingAs($driver);
        $runId = $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93, 'route_id' => $world['route']->id])
            ->assertStatus(202)->json('queue_id');
        $this->postJson('/api/v1/auth/book_a_ride/location/stop')->assertOk();

        Sanctum::actingAs($this->makeUser());
        $this->assertSame([], $this->list($world));
        $this->assertSame('Active', Queue::withoutGlobalScopes()->find($runId)->queue_status->status, 'the trip is still open');
    }

    #[Test]
    public function a_bus_already_on_a_queue_keeps_it_when_it_goes_live(): void
    {
        // Idempotent with the terminus flow: a driver who joined the stage
        // line and then goes live is on THAT queue, not a second run.
        $this->makeQueueStatus('Active', 'Active');
        $world = $this->makeWorld();
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending', 'Pending'), $world['owner']);

        Sanctum::actingAs($this->driver($world));
        $this->postJson(self::PING, ['latitude' => -1.28, 'longitude' => 36.82, 'route_id' => $world['route']->id])
            ->assertStatus(202)->assertJsonPath('queue_id', $queue->id);

        $this->assertSame(1, Queue::withoutGlobalScopes()->count());
    }
}
